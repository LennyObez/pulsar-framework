<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

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
            merchantId: $data['merchant_id'] ?? '',
            apiKey: $data['api_key'] ?? '',
            webhookSecret: $data['webhook_secret'] ?? '',
            environment: $data['environment'] ?? 'ext',
            enabled: (bool) ($data['enabled'] ?? false),
            callbackUrl: $data['callback_url'] ?? '',
            paymentExpirySeconds: $data['payment_expiry_seconds'] ?? 900,
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
