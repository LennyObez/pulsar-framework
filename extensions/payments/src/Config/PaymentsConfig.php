<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Top-level payments configuration DTO.
 */
#[Api]
final readonly class PaymentsConfig
{
    public function __construct(
        public string $provider,
        public string $defaultCurrency,
        public WebhookConfig $webhook,
        public IdempotencyConfig $idempotency,
        public WebhookLogConfig $webhookLog,
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

        /** @var string $provider */
        $provider = $data['provider'] ?? 'null';
        /** @var string $defaultCurrency */
        $defaultCurrency = $data['default_currency'] ?? 'USD';

        return new self(
            provider: $provider,
            defaultCurrency: $defaultCurrency,
            webhook: WebhookConfig::fromArray($webhookData),
            idempotency: IdempotencyConfig::fromArray($idempotencyData),
            webhookLog: WebhookLogConfig::fromArray($webhookLogData),
        );
    }
}
