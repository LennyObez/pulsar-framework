<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport;

use Pulsar\Api\Internal;

/**
 * Minimal HTTP response DTO for API-based mail transports.
 */
#[Internal]
final readonly class MailHttpResponse
{
    public function __construct(
        public int $statusCode,
        public string $body,
    ) {}
}
