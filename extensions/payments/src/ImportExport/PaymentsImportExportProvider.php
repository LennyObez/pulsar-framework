<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\ImportExport;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\ImportExport\ExportRequest;
use Pulsar\ImportExport\ExportResult;
use Pulsar\ImportExport\ImportExportProviderInterface;
use Pulsar\ImportExport\ImportRequest;
use Pulsar\ImportExport\ImportResult;

use function count;
use function in_array;
use function is_array;
use function json_decode;
use function json_encode;
use function sodium_crypto_generichash;

use const JSON_THROW_ON_ERROR;
use const SODIUM_CRYPTO_GENERICHASH_BYTES;

/**
 * Payments import/export provider.
 *
 * Exports/imports payment gateway configurations and pricing plans.
 * Sensitive credentials (API keys, secrets) are always redacted on export.
 */
#[Internal(reason: 'Wired in PaymentsExtension::postBoot()')]
final readonly class PaymentsImportExportProvider implements ImportExportProviderInterface
{
    private const array SUPPORTED_ENTITY_TYPES = [
        'gateway_config',
        'pricing_plans',
    ];

    public function __construct(
        private PaymentsConfig $config,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'payments';
    }

    #[Override]
    public function label(): string
    {
        return 'Payments';
    }

    #[Override]
    public function supportedFormats(): array
    {
        return ['json'];
    }

    #[Override]
    public function export(ExportRequest $request): ExportResult
    {
        $entityTypes = $request->entityTypes !== []
            ? array_values(array_filter(
                $request->entityTypes,
                fn(string $t) => in_array($t, self::SUPPORTED_ENTITY_TYPES, true),
            ))
            : self::SUPPORTED_ENTITY_TYPES;

        $data = [];
        $warnings = [];

        foreach ($entityTypes as $type) {
            $data[$type] = match ($type) {
                'gateway_config' => $this->exportGatewayConfig(),
                'pricing_plans' => $this->exportPricingPlans(),
                default => [],
            };
        }

        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $hash = bin2hex(sodium_crypto_generichash($json, '', SODIUM_CRYPTO_GENERICHASH_BYTES));

        if (!$request->includePii) {
            $warnings[] = 'Gateway credentials redacted: re-enter after import';
        }

        return new ExportResult(
            providerName: 'payments',
            data: $data,
            format: $request->format,
            evidenceHash: $hash,
            entityTypes: $entityTypes,
            warnings: $warnings,
        );
    }

    #[Override]
    public function import(ImportRequest $request): ImportResult
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->content, true, 512, JSON_THROW_ON_ERROR);

        // Payment config is file-based (config/payments.php), not database-driven.
        // Import validates structure and reports what would change.
        $created = [];
        $warnings = [];

        if (isset($payload['gateway_config'])) {
            $warnings[] = 'Gateway configuration import requires manual config file update';
        }

        if (isset($payload['pricing_plans'])) {
            $count = is_array($payload['pricing_plans']) ? count($payload['pricing_plans']) : 0;
            $created['pricing_plans'] = $count;
            $warnings[] = "Found {$count} pricing plan(s): database import not yet implemented";
        }

        return new ImportResult(
            providerName: 'payments',
            created: $created,
            updated: [],
            skipped: [],
            warnings: $warnings,
            errors: [],
            dryRun: $request->dryRun,
        );
    }

    #[Override]
    public function schema(): array
    {
        return [
            'gateway_config' => [
                'provider' => 'string (stripe, paypal, null)',
                'default_currency' => 'string (USD, EUR, etc.)',
                'subscriptions_enabled' => 'bool',
                'invoice_retention_days' => 'int',
                'country_payment_methods' => 'array<country_code, list<method>>',
            ],
            'pricing_plans' => [
                'id' => 'string',
                'name' => 'string',
                'price_amount' => 'int (minor units)',
                'price_currency' => 'string',
                'billing_cycle' => 'string (monthly, yearly)',
                'trial_days' => 'int',
                'discount_basis_points' => 'int|null',
                'active' => 'bool',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exportGatewayConfig(): array
    {
        return [
            'provider' => $this->config->provider,
            'default_currency' => $this->config->defaultCurrency,
            'subscriptions_enabled' => $this->config->subscriptionsEnabled,
            'invoice_retention_days' => $this->config->invoiceRetentionDays,
            'dunning_max_retries' => $this->config->dunningMaxRetries,
            'trial_max_days' => $this->config->trialMaxDays,
            'country_payment_methods' => $this->config->countryPaymentMethods,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportPricingPlans(): array
    {
        // Pricing plans are not yet persisted in a repository --
        // return empty until a PricingPlanRepository is available.
        return [];
    }
}
