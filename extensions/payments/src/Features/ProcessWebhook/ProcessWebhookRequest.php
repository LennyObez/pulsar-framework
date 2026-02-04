<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Features\ProcessWebhook;

/**
 * Request DTO for processing a webhook.
 */
final readonly class ProcessWebhookRequest
{
    public function __construct(
        public string $rawBody,
        public string $signatureHeader,
    ) {}
}
