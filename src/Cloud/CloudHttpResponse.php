<?php

declare(strict_types=1);

namespace Pulsar\Cloud;

use Pulsar\Api\Internal;

/**
 * Minimal HTTP response DTO for cloud provider API calls.
 */
#[Internal]
final readonly class CloudHttpResponse
{
    public function __construct(
        public int $statusCode,
        public string $body,
    ) {}

    /**
     * Check if the response indicates success (2xx status).
     */
    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
