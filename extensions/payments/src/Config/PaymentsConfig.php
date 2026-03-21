<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_int;
use function is_string;

/**
 * Unified payments configuration DTO.
 *
 * Merges one-time payment processing, recurring subscriptions,
 * gateway credentials, and compliance settings into a single config.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PaymentsConfig
{
    /**
     * @param array<string, list<string>> $countryPaymentMethods Per-country payment method overrides
     */
    public function __construct(
        public string $provider,
        public string $defaultCurrency,
        public WebhookConfig $webhook,
        public IdempotencyConfig $idempotency,
        public WebhookLogConfig $webhookLog,
        public StripeConfig $stripe,
        public PayPalConfig $paypal,
        public SepaConfig $sepa,
        public MobileConfig $mobile,
        public PayconiqConfig $payconiq,
        public BancontactConfig $bancontact,
        public IdealConfig $ideal,
        public KlarnaConfig $klarna,
        public bool $subscriptionsEnabled,
        public int $invoiceRetentionDays,
        public int $dunningMaxRetries,
        public int $trialMaxDays,
        public array $countryPaymentMethods = [],
        /**
         * F13.10: when true, every `PaymentGateway` operation refuses to
         * proceed unless an active `TenantContext` is resolved. Multi-
         * tenant deployments should set this to true in `config/payments.php`
         * so a payment cannot accidentally be issued outside a tenant scope
         * — typically a bug in the request pipeline, but in the worst case
         * a cross-tenant resource access vector. Defaults to false to keep
         * single-tenant deployments and CLI workflows working untouched.
         */
        public bool $requireTenantContext = false,
    ) {}

    /**
     * Build from the raw payments config array.
     *
     * @param array<string, mixed> $data Raw array from config/payments.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $webhookData */
        $webhookData = $data['webhook'] ?? [];

        /** @var array<string, mixed> $idempotencyData */
        $idempotencyData = $data['idempotency'] ?? [];

        /** @var array<string, mixed> $webhookLogData */
        $webhookLogData = $data['webhook_log'] ?? [];

        /** @var array<string, mixed> $stripeData */
        $stripeData = $data['stripe'] ?? [];

        /** @var array<string, mixed> $paypalData */
        $paypalData = $data['paypal'] ?? [];

        /** @var array<string, mixed> $sepaData */
        $sepaData = $data['sepa'] ?? [];

        /** @var array<string, mixed> $mobileData */
        $mobileData = $data['mobile'] ?? [];

        /** @var array<string, mixed> $payconiqData */
        $payconiqData = $data['payconiq'] ?? [];

        /** @var array<string, mixed> $bancontactData */
        $bancontactData = $data['bancontact'] ?? [];

        /** @var array<string, mixed> $idealData */
        $idealData = $data['ideal'] ?? [];

        /** @var array<string, mixed> $klarnaData */
        $klarnaData = $data['klarna'] ?? [];

        $providerValue = $data['provider'] ?? null;
        $provider = is_string($providerValue) ? $providerValue : 'null';
        $currencyValue = $data['default_currency'] ?? null;
        $defaultCurrency = is_string($currencyValue) ? $currencyValue : 'USD';
        $subscriptionsEnabled = (bool) ($data['subscriptions_enabled'] ?? true);
        $invoiceRetentionDays = self::int($data, 'invoice_retention_days', 3650);
        $dunningMaxRetries = self::int($data, 'dunning_max_retries', 4);
        $trialMaxDays = self::int($data, 'trial_max_days', 30);
        $requireTenantContext = (bool) ($data['require_tenant_context'] ?? false);

        // Per-country payment method overrides
        $rawCountryMethods = $data['country_payment_methods'] ?? [];
        $countryPaymentMethods = self::parseCountryPaymentMethods($rawCountryMethods);

        return new self(
            provider: $provider,
            defaultCurrency: $defaultCurrency,
            webhook: WebhookConfig::fromArray($webhookData),
            idempotency: IdempotencyConfig::fromArray($idempotencyData),
            webhookLog: WebhookLogConfig::fromArray($webhookLogData),
            stripe: StripeConfig::fromArray($stripeData),
            paypal: PayPalConfig::fromArray($paypalData),
            sepa: SepaConfig::fromArray($sepaData),
            mobile: MobileConfig::fromArray($mobileData),
            payconiq: PayconiqConfig::fromArray($payconiqData),
            bancontact: BancontactConfig::fromArray($bancontactData),
            ideal: IdealConfig::fromArray($idealData),
            klarna: KlarnaConfig::fromArray($klarnaData),
            subscriptionsEnabled: $subscriptionsEnabled,
            invoiceRetentionDays: $invoiceRetentionDays,
            dunningMaxRetries: $dunningMaxRetries,
            trialMaxDays: $trialMaxDays,
            countryPaymentMethods: $countryPaymentMethods,
            requireTenantContext: $requireTenantContext,
        );
    }

    /**
     * Parse per-country payment method overrides.
     *
     * @param mixed $raw Raw config value
     * @return array<string, list<string>>
     */
    private static function parseCountryPaymentMethods(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $result = [];

        /** @var mixed $methods */
        foreach ($raw as $code => $methods) {
            if (!is_string($code) || !is_array($methods)) {
                continue;
            }

            $filtered = [];

            foreach ($methods as $method) {
                if (is_string($method)) {
                    $filtered[] = $method;
                }
            }

            if ($filtered !== []) {
                $result[strtoupper($code)] = $filtered;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function int(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}
