<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function array_keys;
use function is_array;
use function is_string;
use function strtoupper;

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
     * @param array{
     *     provider?: string,
     *     default_currency?: string,
     *     webhook?: array<string, mixed>,
     *     idempotency?: array<string, mixed>,
     *     webhook_log?: array<string, mixed>,
     *     stripe?: array<string, mixed>,
     *     paypal?: array<string, mixed>,
     *     sepa?: array<string, mixed>,
     *     mobile?: array<string, mixed>,
     *     payconiq?: array<string, mixed>,
     *     bancontact?: array<string, mixed>,
     *     ideal?: array<string, mixed>,
     *     klarna?: array<string, mixed>,
     *     subscriptions_enabled?: bool|int|string,
     *     invoice_retention_days?: int,
     *     dunning_max_retries?: int,
     *     trial_max_days?: int,
     *     country_payment_methods?: array<string, list<string>>,
     *     require_tenant_context?: bool|int|string,
     * } $data Raw array from config/payments.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $sub = static function (string $k) use ($data): array {
            $value = $data[$k] ?? null;

            /** @var array<string, mixed> */
            return is_array($value) ? $value : [];
        };

        return new self(
            provider: Coerce::string($data['provider'] ?? null, 'null'),
            defaultCurrency: Coerce::string($data['default_currency'] ?? null, 'USD'),
            webhook: WebhookConfig::fromArray($sub('webhook')),
            idempotency: IdempotencyConfig::fromArray($sub('idempotency')),
            webhookLog: WebhookLogConfig::fromArray($sub('webhook_log')),
            stripe: StripeConfig::fromArray($sub('stripe')),
            paypal: PayPalConfig::fromArray($sub('paypal')),
            sepa: SepaConfig::fromArray($sub('sepa')),
            mobile: MobileConfig::fromArray($sub('mobile')),
            payconiq: PayconiqConfig::fromArray($sub('payconiq')),
            bancontact: BancontactConfig::fromArray($sub('bancontact')),
            ideal: IdealConfig::fromArray($sub('ideal')),
            klarna: KlarnaConfig::fromArray($sub('klarna')),
            subscriptionsEnabled: (bool) ($data['subscriptions_enabled'] ?? true),
            invoiceRetentionDays: Coerce::int($data['invoice_retention_days'] ?? null, 3650),
            dunningMaxRetries: Coerce::int($data['dunning_max_retries'] ?? null, 4),
            trialMaxDays: Coerce::int($data['trial_max_days'] ?? null, 30),
            countryPaymentMethods: self::parseCountryPaymentMethods($sub('country_payment_methods')),
            requireTenantContext: (bool) ($data['require_tenant_context'] ?? false),
        );
    }

    /**
     * Parse per-country payment method overrides.
     *
     * @param array<array-key, mixed> $raw Raw config value
     * @return array<string, list<string>>
     */
    private static function parseCountryPaymentMethods(array $raw): array
    {
        $result = [];

        foreach ($raw as $code => $methods) {
            if (!is_string($code) || !is_array($methods)) {
                continue;
            }
            $filtered = [];

            foreach (array_keys($methods) as $methodKey) {
                if (is_string($methods[$methodKey])) {
                    $filtered[] = $methods[$methodKey];
                }
            }

            if ($filtered !== []) {
                $result[strtoupper($code)] = $filtered;
            }
        }

        return $result;
    }
}
