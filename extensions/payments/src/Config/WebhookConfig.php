<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Webhook sub-configuration.
 */
#[Api]
final readonly class WebhookConfig
{
    public function __construct(
        public string $secret,
        public string $path,
        public int $toleranceSeconds,
        public string $signatureHeader,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            secret: (string) ($data['secret'] ?? ''),
            path: (string) ($data['path'] ?? '/webhooks/payments'),
            toleranceSeconds: (int) ($data['tolerance_seconds'] ?? 300),
            signatureHeader: (string) ($data['signature_header'] ?? 'X-Payments-Signature'),
        );
    }
}
