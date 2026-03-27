<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

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
        return new self(
            provider: $data['provider'] ?? 'null',
            defaultCurrency: $data['default_currency'] ?? 'USD',
            webhook: WebhookConfig::fromArray($data['webhook'] ?? []),
            idempotency: IdempotencyConfig::fromArray($data['idempotency'] ?? []),
            webhookLog: WebhookLogConfig::fromArray($data['webhook_log'] ?? []),
            stripe: StripeConfig::fromArray($data['stripe'] ?? []),
            paypal: PayPalConfig::fromArray($data['paypal'] ?? []),
            sepa: SepaConfig::fromArray($data['sepa'] ?? []),
            mobile: MobileConfig::fromArray($data['mobile'] ?? []),
            payconiq: PayconiqConfig::fromArray($data['payconiq'] ?? []),
            bancontact: BancontactConfig::fromArray($data['bancontact'] ?? []),
            ideal: IdealConfig::fromArray($data['ideal'] ?? []),
            klarna: KlarnaConfig::fromArray($data['klarna'] ?? []),
            subscriptionsEnabled: (bool) ($data['subscriptions_enabled'] ?? true),
            invoiceRetentionDays: $data['invoice_retention_days'] ?? 3650,
            dunningMaxRetries: $data['dunning_max_retries'] ?? 4,
            trialMaxDays: $data['trial_max_days'] ?? 30,
            countryPaymentMethods: self::parseCountryPaymentMethods($data['country_payment_methods'] ?? []),
            requireTenantContext: (bool) ($data['require_tenant_context'] ?? false),
        );
    }

    /**
     * Parse per-country payment method overrides.
     *
     * @param array<string, list<string>> $raw Raw config value
     * @return array<string, list<string>>
     */
    private static function parseCountryPaymentMethods(array $raw): array
    {
        $result = [];

        foreach ($raw as $code => $methods) {
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
}
