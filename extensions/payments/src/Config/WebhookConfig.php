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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var string $secret */
        $secret = $data['secret'] ?? '';
        /** @var string $path */
        $path = $data['path'] ?? '/webhooks/payments';
        /** @var int $toleranceSeconds */
        $toleranceSeconds = $data['tolerance_seconds'] ?? 300;
        /** @var string $signatureHeader */
        $signatureHeader = $data['signature_header'] ?? 'X-Payments-Signature';

        return new self(
            secret: $secret,
            path: $path,
            toleranceSeconds: $toleranceSeconds,
            signatureHeader: $signatureHeader,
        );
    }
}
