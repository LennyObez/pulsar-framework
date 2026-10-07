<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\ImportExport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\ImportExport\PaymentsImportExportProvider;
use Pulsar\ImportExport\ExportRequest;
use Pulsar\ImportExport\ImportRequest;

use function json_encode;

#[CoversClass(PaymentsImportExportProvider::class)]
final class PaymentsImportExportProviderTest extends TestCase
{
    private PaymentsImportExportProvider $provider;

    protected function setUp(): void
    {
        $config = PaymentsConfig::fromArray([
            'provider' => 'stripe',
            'default_currency' => 'EUR',
            'subscriptions_enabled' => true,
            'invoice_retention_days' => 365,
            'dunning_max_retries' => 3,
            'trial_max_days' => 14,
        ]);

        $this->provider = new PaymentsImportExportProvider($config);
    }

    #[Test]
    public function nameReturnsPayments(): void
    {
        self::assertSame('payments', $this->provider->name());
    }

    #[Test]
    public function labelReturnsHumanReadable(): void
    {
        self::assertSame('Payments', $this->provider->label());
    }

    #[Test]
    public function supportsJsonFormat(): void
    {
        self::assertSame(['json'], $this->provider->supportedFormats());
    }

    #[Test]
    public function exportGatewayConfigRedactsCredentials(): void
    {
        $result = $this->provider->export(new ExportRequest());

        self::assertSame('payments', $result->providerName);
        self::assertArrayHasKey('gateway_config', $result->data);

        /** @var array<string, mixed> $config */
        $config = $result->data['gateway_config'];
        self::assertSame('stripe', $config['provider']);
        self::assertSame('EUR', $config['default_currency']);
        self::assertTrue($config['subscriptions_enabled']);

        // Should warn about redacted credentials
        self::assertNotEmpty($result->warnings);
        self::assertStringContainsString('redacted', $result->warnings[0]);
    }

    #[Test]
    public function exportIncludesPricingPlansKey(): void
    {
        $result = $this->provider->export(new ExportRequest(entityTypes: ['pricing_plans']));

        self::assertArrayHasKey('pricing_plans', $result->data);
    }

    #[Test]
    public function importWarnsAboutConfigBasedGateway(): void
    {
        $content = json_encode([
            'gateway_config' => ['provider' => 'paypal'],
            'pricing_plans' => [['id' => 'plan-1', 'name' => 'Pro']],
        ]);
        self::assertIsString($content);

        $result = $this->provider->import(new ImportRequest(content: $content));

        self::assertSame('payments', $result->providerName);
        self::assertNotEmpty($result->warnings);
    }

    #[Test]
    public function schemaDescribesAllEntityTypes(): void
    {
        $schema = $this->provider->schema();

        self::assertArrayHasKey('gateway_config', $schema);
        self::assertArrayHasKey('pricing_plans', $schema);
        self::assertArrayHasKey('provider', $schema['gateway_config']);
        self::assertArrayHasKey('price_amount', $schema['pricing_plans']);
    }
}
