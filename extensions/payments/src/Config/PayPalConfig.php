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
     * @param array{
     *     client_id?: string,
     *     client_secret?: string,
     *     webhook_id?: string,
     *     sandbox?: bool|int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            clientId: $data['client_id'] ?? '',
            clientSecret: $data['client_secret'] ?? '',
            webhookId: $data['webhook_id'] ?? '',
            sandbox: (bool) ($data['sandbox'] ?? true),
        );
    }
}
