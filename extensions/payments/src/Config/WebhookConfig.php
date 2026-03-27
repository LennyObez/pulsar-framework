<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Webhook sub-configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class WebhookConfig
{
    public function __construct(
        public string $secret,
        public string $path,
        public int $toleranceSeconds,
        public string $signatureHeader,
    ) {}

    /**
     * @param array{
     *     secret?: string,
     *     path?: string,
     *     tolerance_seconds?: int,
     *     signature_header?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            secret: $data['secret'] ?? '',
            path: $data['path'] ?? '/webhooks/payments',
            toleranceSeconds: $data['tolerance_seconds'] ?? 300,
            signatureHeader: $data['signature_header'] ?? 'X-Payments-Signature',
        );
    }
}
