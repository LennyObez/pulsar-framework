<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * PayPal gateway configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PayPalConfig
{
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public string $webhookId,
        public bool $sandbox,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var string $clientId */
        $clientId = $data['client_id'] ?? '';
        /** @var string $clientSecret */
        $clientSecret = $data['client_secret'] ?? '';
        /** @var string $webhookId */
        $webhookId = $data['webhook_id'] ?? '';
        /** @var bool $sandbox */
        $sandbox = (bool) ($data['sandbox'] ?? true);

        return new self(
            clientId: $clientId,
            clientSecret: $clientSecret,
            webhookId: $webhookId,
            sandbox: $sandbox,
        );
    }
}
