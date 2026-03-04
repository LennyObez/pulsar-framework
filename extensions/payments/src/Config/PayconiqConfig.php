<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_int;
use function is_string;

/**
 * Payconiq payment gateway configuration.
 *
 * Payconiq is a mobile payment solution popular in Belgium, Luxembourg,
 * and the Netherlands. Supports both online and point-of-sale payments.
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $merchantIdVal = $data['merchant_id'] ?? null;
        $merchantId = is_string($merchantIdVal) ? $merchantIdVal : '';
        $apiKeyVal = $data['api_key'] ?? null;
        $apiKey = is_string($apiKeyVal) ? $apiKeyVal : '';
        $webhookSecretVal = $data['webhook_secret'] ?? null;
        $webhookSecret = is_string($webhookSecretVal) ? $webhookSecretVal : '';
        $environmentVal = $data['environment'] ?? null;
        $environment = is_string($environmentVal) ? $environmentVal : 'ext';
        $enabled = (bool) ($data['enabled'] ?? false);
        $callbackUrlVal = $data['callback_url'] ?? null;
        $callbackUrl = is_string($callbackUrlVal) ? $callbackUrlVal : '';
        $expiryVal = $data['payment_expiry_seconds'] ?? null;
        $paymentExpirySeconds = is_int($expiryVal) ? $expiryVal : 900;

        return new self(
            merchantId: $merchantId,
            apiKey: $apiKey,
            webhookSecret: $webhookSecret,
            environment: $environment,
            enabled: $enabled,
            callbackUrl: $callbackUrl,
            paymentExpirySeconds: $paymentExpirySeconds,
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
