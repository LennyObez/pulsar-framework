<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Security;

use Pulsar\Api\Api;

/**
 * Response from the SSRF-safe HTTP client.
 */
#[Api(since: '1.0.0')]
final readonly class SafeHttpResponse
{
    /**
     * @param int $statusCode HTTP status code
     * @param array<string, list<string>> $headers Response headers
     * @param string $body Response body
     * @param string $effectiveUrl Final URL after redirects
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
        public string $effectiveUrl,
    ) {}
}
