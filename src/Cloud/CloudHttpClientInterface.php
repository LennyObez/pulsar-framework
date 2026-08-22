<?php

declare(strict_types=1);

namespace Pulsar\Cloud;

use Pulsar\Api\Internal;

/**
 * Transport seam for cloud provider adapters.
 *
 * Extracted so adapters can be unit-tested against controlled responses (e.g.
 * an S3 UploadPart response carrying — or missing — its ETag header) without
 * real network access. Production wiring uses {@see CloudHttpClient}.
 */
#[Internal]
interface CloudHttpClientInterface
{
    /**
     * Send an HTTP request and return the response.
     *
     * @param array<string, string> $headers Request headers
     *
     * @throws CloudException On connection/transport failure
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
    ): CloudHttpResponse;
}
