#!/usr/bin/env bash

set -Eeuo pipefail

readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
readonly VERSION_FILE="${REPOSITORY_ROOT}/src/Version.php"
readonly SSH_CONFIG="${HOME}/.ssh/config"
readonly DEFAULT_REPOSITORY="BiqliLLC/biqli-php-sdk"

VERSION_BACKUP=""
VERSION_CHANGED=0
RELEASE_COMMITTED=0
STAGING_STARTED=0

die() {
    printf 'Error: %s\n' "$*" >&2
    exit 1
}

note() {
    printf '\n==> %s\n' "$*"
}

confirm() {
    local prompt="$1"
    local reply

    read -r -p "${prompt} [y/N]: " reply
    [[ "${reply}" =~ ^[Yy]$ ]]
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"
}

cleanup() {
    if [[ "${VERSION_CHANGED}" -eq 1 && "${RELEASE_COMMITTED}" -eq 0 && -n "${VERSION_BACKUP}" ]]; then
        if [[ "${STAGING_STARTED}" -eq 1 ]]; then
            git reset -q HEAD -- . 2>/dev/null || true
        fi
        cp -p -- "${VERSION_BACKUP}" "${VERSION_FILE}"
        printf '\nRestored %s because the release was not committed.\n' "${VERSION_FILE#${REPOSITORY_ROOT}/}" >&2
    fi

    [[ -z "${VERSION_BACKUP}" ]] || rm -f -- "${VERSION_BACKUP}"
}

trap cleanup EXIT

read_version() {
    local version

    version="$(sed -nE "s/^[[:space:]]*public const SDK_VERSION = '([0-9]+\.[0-9]+\.[0-9]+)';[[:space:]]*$/\1/p" "${VERSION_FILE}")"
    [[ "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "Could not read a stable semantic version from src/Version.php."
    printf '%s' "${version}"
}

bump_version() {
    local version="$1"
    local release_type="$2"
    local major minor patch

    IFS=. read -r major minor patch <<<"${version}"

    case "${release_type}" in
        patch) patch=$((patch + 1)) ;;
        minor) minor=$((minor + 1)); patch=0 ;;
        major) major=$((major + 1)); minor=0; patch=0 ;;
        *) die "Unsupported release type: ${release_type}" ;;
    esac

    printf '%s.%s.%s' "${major}" "${minor}" "${patch}"
}

configured_github_aliases() {
    [[ -f "${SSH_CONFIG}" ]] || return 0

    awk '
        tolower($1) == "host" {
            host = $2
            next
        }
        tolower($1) == "hostname" && tolower($2) == "github.com" && host != "" {
            if (host !~ /[*?!]/) print host
            host = ""
        }
    ' "${SSH_CONFIG}" | awk '!seen[$0]++'
}

ssh_alias_exists() {
    local wanted="$1"
    local alias

    while IFS= read -r alias; do
        [[ "${alias}" == "${wanted}" ]] && return 0
    done < <(configured_github_aliases)

    return 1
}

test_github_authentication() {
    local alias="$1"
    local output status

    note "Testing GitHub authentication through ${alias}"
    set +e
    output="$(ssh -T "git@${alias}" 2>&1)"
    status=$?
    set -e
    printf '%s\n' "${output}"

    if grep -Fq 'successfully authenticated' <<<"${output}"; then
        return 0
    fi

    printf 'SSH exited with status %s. GitHub normally returns status 1 even after successful authentication.\n' "${status}" >&2
    return 1
}

set_origin_for_alias() {
    local alias="$1"
    local remote="git@${alias}:${DEFAULT_REPOSITORY}.git"

    if git remote get-url origin >/dev/null 2>&1; then
        git remote set-url origin "${remote}"
    else
        git remote add origin "${remote}"
    fi

    printf 'Repository origin: %s\n' "$(git remote get-url origin)"
}

create_github_profile() {
    local alias key_name key_path comment

    read -r -p 'SSH profile alias (example: github-biqlillc): ' alias
    [[ "${alias}" =~ ^[A-Za-z0-9._-]+$ ]] || die "The SSH profile alias contains unsupported characters."
    ssh_alias_exists "${alias}" && die "SSH profile '${alias}' already exists. Choose it instead of recreating it."

    key_name="id_ed25519_${alias#github-}"
    read -r -p "SSH key filename [${key_name}]: " key_name
    key_name="${key_name:-id_ed25519_${alias#github-}}"
    [[ "${key_name}" =~ ^[A-Za-z0-9._-]+$ ]] || die "The SSH key filename contains unsupported characters."
    key_path="${HOME}/.ssh/${key_name}"
    [[ ! -e "${key_path}" && ! -e "${key_path}.pub" ]] || die "The selected SSH key path already exists."

    read -r -p "SSH key comment [github-${alias#github-}]: " comment
    comment="${comment:-github-${alias#github-}}"

    install -d -m 700 "${HOME}/.ssh"
    note "Generating ${key_path}. Use a passphrase when prompted."
    ssh-keygen -t ed25519 -C "${comment}" -f "${key_path}"

    {
        printf '\n# Biqli SDK release profile: %s\n' "${alias}"
        printf 'Host %s\n' "${alias}"
        printf '    HostName github.com\n'
        printf '    User git\n'
        printf '    IdentityFile %s\n' "${key_path}"
        printf '    IdentitiesOnly yes\n'
    } >>"${SSH_CONFIG}"
    chmod 600 "${SSH_CONFIG}"

    note 'Add this public key to the GitHub user with write access to the repository'
    printf '%s\n' 'GitHub -> Settings -> SSH and GPG keys -> New SSH key'
    printf '\n%s\n\n' "$(<"${key_path}.pub")"
    read -r -p 'Press Enter after the key has been added and authorized on GitHub. '

    test_github_authentication "${alias}" || die "GitHub did not accept the selected SSH profile."
    set_origin_for_alias "${alias}"
}

choose_github_profile() {
    local -a aliases=()
    local alias selection

    while IFS= read -r alias; do
        [[ -z "${alias}" ]] || aliases+=("${alias}")
    done < <(configured_github_aliases)

    if [[ "${#aliases[@]}" -eq 0 ]]; then
        printf 'No configured GitHub SSH profiles were found.\n'
        create_github_profile
        return
    fi

    printf '\nConfigured GitHub SSH profiles:\n'
    for selection in "${!aliases[@]}"; do
        printf '  %d) %s\n' "$((selection + 1))" "${aliases[$selection]}"
    done
    printf '  %d) Create a new profile\n' "$(( ${#aliases[@]} + 1 ))"
    printf '  %d) Cancel\n' "$(( ${#aliases[@]} + 2 ))"

    read -r -p 'Select a profile: ' selection
    [[ "${selection}" =~ ^[0-9]+$ ]] || die "Invalid profile selection."

    if (( selection >= 1 && selection <= ${#aliases[@]} )); then
        alias="${aliases[$((selection - 1))]}"
        test_github_authentication "${alias}" || die "GitHub did not accept the selected SSH profile."
        set_origin_for_alias "${alias}"
    elif (( selection == ${#aliases[@]} + 1 )); then
        create_github_profile
    else
        die "Cancelled."
    fi
}

ensure_github_access() {
    local current_remote alias reply

    current_remote="$(git remote get-url origin 2>/dev/null || true)"
    if [[ "${current_remote}" =~ ^git@([^:]+):${DEFAULT_REPOSITORY}(\.git)?$ ]]; then
        alias="${BASH_REMATCH[1]}"
        printf 'Current repository origin: %s\n' "${current_remote}"
        read -r -p "Use its SSH profile '${alias}'? [Y/n]: " reply
        if [[ ! "${reply}" =~ ^[Nn]$ ]]; then
            if test_github_authentication "${alias}"; then
                return
            fi
            printf 'The current profile could not authenticate. Choose or create another profile.\n' >&2
        fi
    elif [[ -n "${current_remote}" ]]; then
        printf 'Current origin is not the expected dedicated SSH URL for %s: %s\n' "${DEFAULT_REPOSITORY}" "${current_remote}"
    fi

    choose_github_profile
}

remote_tag_exists() {
    local tag="$1"
    local output

    output="$(git ls-remote --tags origin "refs/tags/${tag}")" || die "Could not query release tags from origin."
    [[ -n "${output}" ]]
}

ensure_git_identity() {
    local local_name local_email effective_name effective_email reply

    local_name="$(git config --local --get user.name 2>/dev/null || true)"
    local_email="$(git config --local --get user.email 2>/dev/null || true)"
    effective_name="$(git config --get user.name 2>/dev/null || true)"
    effective_email="$(git config --get user.email 2>/dev/null || true)"

    if [[ -n "${local_name}" && -n "${local_email}" ]]; then
        printf 'Repository commit identity: %s <%s>\n' "${local_name}" "${local_email}"
        return
    fi

    if [[ -n "${effective_name}" && -n "${effective_email}" ]]; then
        printf 'Current Git commit identity: %s <%s>\n' "${effective_name}" "${effective_email}"
        read -r -p 'Pin this identity to the PHP SDK repository? [y/N]: ' reply
        if [[ "${reply}" =~ ^[Yy]$ ]]; then
            git config --local user.name "${effective_name}"
            git config --local user.email "${effective_email}"
            return
        fi
    fi

    read -r -p 'Git commit name: ' local_name
    read -r -p 'Git commit email: ' local_email
    [[ -n "${local_name}" && -n "${local_email}" ]] || die "Both Git commit name and email are required."
    [[ "${local_email}" != *[[:space:]]* && "${local_email}" == *@* ]] || die "Enter a valid Git commit email."
    git config --local user.name "${local_name}"
    git config --local user.email "${local_email}"
}

resume_interrupted_release_if_needed() {
    local version="$1"
    local tag="v${version}"
    local tag_commit head_commit subject

    if remote_tag_exists "${tag}"; then
        return 1
    fi

    head_commit="$(git rev-parse HEAD)"
    subject="$(git log -1 --pretty=%s)"
    tag_commit="$(git rev-list -n 1 "${tag}" 2>/dev/null || true)"

    if [[ -n "${tag_commit}" && "${tag_commit}" != "${head_commit}" ]]; then
        die "Local tag ${tag} exists on a different commit and is not present remotely. Resolve it manually."
    fi

    if [[ -n "${tag_commit}" || "${subject}" == "Release ${tag}" ]]; then
        note "An interrupted ${tag} release was detected"
        printf 'HEAD: %s\n' "${subject}"
        confirm "Finish publishing ${tag}?" || die "Cancelled."

        if [[ -z "${tag_commit}" ]]; then
            git tag -a "${tag}" -m "Biqli PHP SDK ${tag}"
        fi
        git push origin main
        git push origin "${tag}"
        note "Release ${tag} completed"
        printf 'Packagist will synchronize through its GitHub hook.\n'
        exit 0
    fi

    return 1
}

ensure_repository_ready() {
    local branch ahead behind
    local -a untracked=()

    [[ -d "${REPOSITORY_ROOT}/.git" ]] || die "This SDK directory is not an independent Git repository."
    [[ -f "${VERSION_FILE}" ]] || die "Missing src/Version.php."

    branch="$(git branch --show-current)"
    [[ "${branch}" == 'main' ]] || die "Releases must be created from main; current branch: ${branch:-detached HEAD}."

    git diff --cached --quiet || die "Staged changes were found. Commit or unstage them before running the release tool."
    git diff --quiet -- src/Version.php || die "src/Version.php has uncommitted changes. Restore or commit them before releasing."

    mapfile -t untracked < <(git ls-files --others --exclude-standard)
    if [[ "${#untracked[@]}" -gt 0 ]]; then
        printf 'Untracked files were found and will not be staged automatically:\n' >&2
        printf '  %s\n' "${untracked[@]}" >&2
        die "Review and commit or ignore these files before releasing."
    fi

    note 'Fetching the current main branch and release tags'
    git fetch --prune origin main
    git fetch --prune --tags origin

    read -r behind ahead < <(git rev-list --left-right --count origin/main...HEAD)
    (( behind == 0 )) || die "Local main is behind or diverged from origin/main. Update it with a fast-forward merge first."

    if (( ahead > 0 )); then
        printf 'Local main contains %d commit(s) not yet pushed; they will be included in the release.\n' "${ahead}"
    fi
}

select_release_type() {
    local current="$1"
    local selection

    {
        printf '\nCurrent version: %s\n\n' "${current}"
        printf 'Select release type:\n'
        printf '  1) Patch -> %s\n' "$(bump_version "${current}" patch)"
        printf '  2) Minor -> %s\n' "$(bump_version "${current}" minor)"
        printf '  3) Major -> %s\n' "$(bump_version "${current}" major)"
        printf '  4) Cancel\n'
    } >&2
    read -r -p 'Selection: ' selection

    case "${selection}" in
        1) printf 'patch' ;;
        2) printf 'minor' ;;
        3) printf 'major' ;;
        4) die "Cancelled." ;;
        *) die "Invalid release selection." ;;
    esac
}

update_version_file() {
    local current="$1"
    local next="$2"
    local before after

    before="$(grep -Fc "public const SDK_VERSION = '${current}';" "${VERSION_FILE}" || true)"
    [[ "${before}" -eq 1 ]] || die "Expected exactly one SDK_VERSION declaration for ${current}."

    VERSION_BACKUP="$(mktemp)"
    cp -p -- "${VERSION_FILE}" "${VERSION_BACKUP}"
    VERSION_CHANGED=1

    sed -i -E "s/public const SDK_VERSION = '${current}';/public const SDK_VERSION = '${next}';/" "${VERSION_FILE}"
    after="$(grep -Fc "public const SDK_VERSION = '${next}';" "${VERSION_FILE}" || true)"
    [[ "${after}" -eq 1 ]] || die "Failed to update src/Version.php safely."
}

run_release_checks() {
    note 'Validating Composer metadata'
    composer validate --strict

    note 'Running the SDK smoke test'
    php smoke/sdk.php
}

publish_release() {
    local current_version release_type next_version tag confirmation

    current_version="$(read_version)"
    resume_interrupted_release_if_needed "${current_version}" || true

    release_type="$(select_release_type "${current_version}")"
    next_version="$(bump_version "${current_version}" "${release_type}")"
    tag="v${next_version}"

    if git rev-parse -q --verify "refs/tags/${tag}" >/dev/null || remote_tag_exists "${tag}"; then
        die "Release tag ${tag} already exists. Published versions are immutable."
    fi

    update_version_file "${current_version}" "${next_version}"
    run_release_checks

    note "Release plan for ${tag}"
    git status --short
    printf '\n'
    git diff --stat
    printf '\nThis will:\n'
    printf '  1. Commit all displayed tracked changes as "Release %s".\n' "${tag}"
    printf '  2. Push main to %s.\n' "$(git remote get-url origin)"
    printf '  3. Create and push the immutable annotated tag %s.\n' "${tag}"
    printf '  4. Allow the GitHub/Packagist hook to synchronize the release.\n\n'

    read -r -p "Type ${tag} to publish, or press Enter to cancel: " confirmation
    [[ "${confirmation}" == "${tag}" ]] || die "Cancelled."

    git add -u
    STAGING_STARTED=1
    [[ -n "$(git diff --cached --name-only)" ]] || die "There are no tracked changes to release."
    git commit -m "Release ${tag}"
    RELEASE_COMMITTED=1
    VERSION_CHANGED=0
    git push origin main
    git tag -a "${tag}" -m "Biqli PHP SDK ${tag}"
    git push origin "${tag}"

    note "Biqli PHP SDK ${tag} published"
    printf 'Packagist will synchronize through its GitHub hook.\n'
}

main() {
    require_command git
    require_command ssh
    require_command ssh-keygen
    require_command composer
    require_command php
    require_command sed

    cd -- "${REPOSITORY_ROOT}"

    printf '========================================\n'
    printf '       BIQLI PHP SDK RELEASE TOOL       \n'
    printf '========================================\n\n'

    ensure_github_access
    ensure_git_identity
    ensure_repository_ready
    publish_release
}

main "$@"
