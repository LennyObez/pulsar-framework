<?php

declare(strict_types=1);

namespace Pulsar\Cloud;

use Pulsar\Api\Internal;

use function strtolower;

/**
 * Minimal HTTP response DTO for cloud provider API calls.
 */
#[Internal]
final readonly class CloudHttpResponse
{
    /**
     * @param array<string, string> $headers Response headers keyed by lowercased name
     */
    public function __construct(
        public int $statusCode,
        public string $body,
        public array $headers = [],
    ) {}

    /**
     * Check if the response indicates success (2xx status).
     */
    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Case-insensitive single-header lookup; null when absent.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
