<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Extension\Studio\Config\StudioCollectorConfig;

final class StudioCollectorConfigTest extends TestCase
{
    #[Test]
    public function defaultsAllCollectorsEnabled(): void
    {
        $config = new StudioCollectorConfig();

        self::assertTrue($config->http);
        self::assertTrue($config->database);
        self::assertTrue($config->logs);
        self::assertTrue($config->exceptions);
        self::assertTrue($config->scheduler);
        self::assertTrue($config->featureFlags);
        self::assertTrue($config->queue);
        self::assertTrue($config->benchmark);
        self::assertTrue($config->runtime);
        self::assertFalse($config->storeRawSql);
        self::assertFalse($config->redactTableNames);
    }

    #[Test]
    public function fromArrayDisablesIndividualCollectors(): void
    {
        $env = Environment::load();
        $config = StudioCollectorConfig::fromArray([
            'http' => ['enabled' => false],
            'database' => ['enabled' => false],
            'logs' => ['enabled' => false],
        ], $env);

        self::assertFalse($config->http);
        self::assertFalse($config->database);
        self::assertFalse($config->logs);
        self::assertTrue($config->exceptions);
    }

    #[Test]
    public function fromArrayReadsStoreRawSql(): void
    {
        $env = Environment::load();
        $config = StudioCollectorConfig::fromArray([
            'database' => ['store_raw_sql' => true],
        ], $env);

        self::assertTrue($config->storeRawSql);
    }

    #[Test]
    public function fromArrayReadsRedactTableNames(): void
    {
        $env = Environment::load();
        $config = StudioCollectorConfig::fromArray([
            'database' => ['redact_table_names' => true],
        ], $env);

        self::assertTrue($config->redactTableNames);
    }
}
