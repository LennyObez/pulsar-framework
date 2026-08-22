<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Payconiq payment gateway configuration.
 *
 * Payconiq is a mobile payment solution popular in Belgium, Luxembourg,
 * and the Netherlands. Supports both online and point-of-sale payments.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PayconiqConfig
{
    public function __construct(
        public string $merchantId,
        public string $apiKey,
        public string $webhookSecret,
        public string $environment,
        public bool $enabled,
        public string $callbackUrl,
        public int $paymentExpirySeconds,
    ) {}

    /**
     * @param array{
     *     merchant_id?: string,
     *     api_key?: string,
     *     webhook_secret?: string,
     *     environment?: string,
     *     enabled?: bool|int|string,
     *     callback_url?: string,
     *     payment_expiry_seconds?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            merchantId: Coerce::string($data['merchant_id'] ?? null),
            apiKey: Coerce::string($data['api_key'] ?? null),
            webhookSecret: Coerce::string($data['webhook_secret'] ?? null),
            environment: Coerce::string($data['environment'] ?? null, 'ext'),
            enabled: (bool) ($data['enabled'] ?? false),
            callbackUrl: Coerce::string($data['callback_url'] ?? null),
            paymentExpirySeconds: Coerce::int($data['payment_expiry_seconds'] ?? null, 900),
        );
    }

    /**
     * Get the Payconiq API base URL for the configured environment.
     */
    #[NoDiscard]
    public function apiBaseUrl(): string
    {
        return match ($this->environment) {
            'prod', 'production' => 'https://api.payconiq.com/v3',
            default => 'https://api.ext.payconiq.com/v3',
        };
    }
}
