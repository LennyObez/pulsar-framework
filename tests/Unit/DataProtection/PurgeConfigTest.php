<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\PurgeConfig;

#[CoversClass(PurgeConfig::class)]
final class PurgeConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new PurgeConfig();

        self::assertSame(1000, $config->batchSize);
        self::assertTrue($config->auditPurgeOperations);
        self::assertFalse($config->dryRun);
    }

    #[Test]
    public function constructionStoresAllProperties(): void
    {
        $config = new PurgeConfig(
            batchSize: 500,
            auditPurgeOperations: false,
            dryRun: true,
        );

        self::assertSame(500, $config->batchSize);
        self::assertFalse($config->auditPurgeOperations);
        self::assertTrue($config->dryRun);
    }

    #[Test]
    public function fromArrayPopulatesAllFields(): void
    {
        $config = PurgeConfig::fromArray([
            'batch_size' => 250,
            'audit_purge_operations' => false,
            'dry_run' => true,
        ]);

        self::assertSame(250, $config->batchSize);
        self::assertFalse($config->auditPurgeOperations);
        self::assertTrue($config->dryRun);
    }

    #[Test]
    public function fromArrayDefaultsOnMissingKeys(): void
    {
        $config = PurgeConfig::fromArray([]);

        self::assertSame(1000, $config->batchSize);
        self::assertTrue($config->auditPurgeOperations);
        self::assertFalse($config->dryRun);
    }

    #[Test]
    public function fromArrayHandlesInvalidTypes(): void
    {
        $config = PurgeConfig::fromArray([
            'batch_size' => 'not_an_int',
            'audit_purge_operations' => 42,
            'dry_run' => 'yes',
        ]);

        self::assertSame(1000, $config->batchSize);
        self::assertTrue($config->auditPurgeOperations);
        self::assertFalse($config->dryRun);
    }
}
