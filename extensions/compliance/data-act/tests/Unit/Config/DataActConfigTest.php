<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\DataAct\Config\DataActConfig;

#[CoversClass(DataActConfig::class)]
final class DataActConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new DataActConfig();

        self::assertFalse($config->enabled);
        self::assertSame('data_holder', $config->entityRole);
        self::assertSame(30, $config->portabilityMaxDays);
        self::assertSame(30, $config->switchingTransitionDays);
        self::assertSame('json', $config->defaultExportFormat);
        self::assertTrue($config->enableAccessLogging);
        self::assertSame(1825, $config->accessLogRetentionDays);
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $config = DataActConfig::fromArray([
            'enabled' => true,
            'entity_role' => 'cloud_provider',
            'portability_max_days' => 15,
            'switching_transition_days' => 45,
            'default_export_format' => 'csv',
            'enable_access_logging' => false,
            'access_log_retention_days' => 365,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('cloud_provider', $config->entityRole);
        self::assertSame(15, $config->portabilityMaxDays);
        self::assertSame(45, $config->switchingTransitionDays);
        self::assertSame('csv', $config->defaultExportFormat);
        self::assertFalse($config->enableAccessLogging);
        self::assertSame(365, $config->accessLogRetentionDays);
    }

    #[Test]
    public function fromArrayWithEmptyDataUsesDefaults(): void
    {
        $config = DataActConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('data_holder', $config->entityRole);
        self::assertSame(30, $config->portabilityMaxDays);
    }

    #[Test]
    public function fromArrayIgnoresInvalidTypes(): void
    {
        $config = DataActConfig::fromArray([
            'enabled' => 'yes',
            'entity_role' => 123,
            'portability_max_days' => 'thirty',
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('data_holder', $config->entityRole);
        self::assertSame(30, $config->portabilityMaxDays);
    }

    #[Test]
    public function isDataHolder(): void
    {
        $config = new DataActConfig(entityRole: 'data_holder');
        self::assertTrue($config->isDataHolder());
        self::assertFalse($config->isCloudProvider());
        self::assertFalse($config->isDataRecipient());
    }

    #[Test]
    public function isCloudProvider(): void
    {
        $config = new DataActConfig(entityRole: 'cloud_provider');
        self::assertFalse($config->isDataHolder());
        self::assertTrue($config->isCloudProvider());
        self::assertFalse($config->isDataRecipient());
    }

    #[Test]
    public function isDataRecipient(): void
    {
        $config = new DataActConfig(entityRole: 'data_recipient');
        self::assertFalse($config->isDataHolder());
        self::assertFalse($config->isCloudProvider());
        self::assertTrue($config->isDataRecipient());
    }
}
