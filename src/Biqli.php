<?php

declare(strict_types=1);

namespace Biqli\Sdk;

use Biqli\Sdk\Internal\HttpClient;

final class Biqli
{
    public readonly Tracking $track;

    /**
     * @param array{
     *   baseUrl?: string,
     *   timeoutMs?: int,
     *   clientName?: string,
     *   clientVersion?: string,
     *   retry?: array{maxRetries?: int, baseDelayMs?: int, maxDelayMs?: int, jitter?: bool},
     *   httpClient?: \GuzzleHttp\ClientInterface
     * } $options
     */
    public function __construct(string $apiKey, array $options = [])
    {
        $this->track = new Tracking(new HttpClient($apiKey, $options));
    }
}
