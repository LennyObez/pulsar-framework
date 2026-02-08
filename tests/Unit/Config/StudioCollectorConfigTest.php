<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Extension\Studio\Config\StudioCollectorConfig;

#[CoversClass(StudioCollectorConfig::class)]
final class StudioCollectorConfigTest extends TestCase
{
    /** @var list<string> Env vars set during tests, to be cleaned up */
    private array $envVarsToClean = [];

    protected function tearDown(): void
    {
        foreach ($this->envVarsToClean as $key) {
            putenv($key);
        }

        $this->envVarsToClean = [];
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $this->envVarsToClean[] = $key;
    }

    #[Test]
    public function it_has_sensible_defaults(): void
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
    public function it_creates_from_array_with_full_data(): void
    {
        $env = Environment::load(null);

        $result = StudioCollectorConfig::fromArray([
            'http' => ['enabled' => false],
            'database' => [
                'enabled' => false,
                'store_raw_sql' => true,
                'redact_table_names' => true,
            ],
            'logs' => ['enabled' => false],
            'exceptions' => ['enabled' => false],
            'scheduler' => ['enabled' => false],
            'feature_flags' => ['enabled' => false],
            'queue' => ['enabled' => false],
            'benchmark' => ['enabled' => false],
            'runtime' => ['enabled' => false],
        ], $env);

        self::assertFalse($result->http);
        self::assertFalse($result->database);
        self::assertFalse($result->logs);
        self::assertFalse($result->exceptions);
        self::assertFalse($result->scheduler);
        self::assertFalse($result->featureFlags);
        self::assertFalse($result->queue);
        self::assertFalse($result->benchmark);
        self::assertFalse($result->runtime);
        self::assertTrue($result->storeRawSql);
        self::assertTrue($result->redactTableNames);
    }

    #[Test]
    public function it_uses_defaults_when_array_is_empty(): void
    {
        $env = Environment::load(null);

        $result = StudioCollectorConfig::fromArray([], $env);

        self::assertTrue($result->http);
        self::assertTrue($result->database);
        self::assertTrue($result->logs);
        self::assertTrue($result->exceptions);
        self::assertTrue($result->scheduler);
        self::assertTrue($result->featureFlags);
        self::assertTrue($result->queue);
        self::assertTrue($result->benchmark);
        self::assertTrue($result->runtime);
        self::assertFalse($result->storeRawSql);
        self::assertFalse($result->redactTableNames);
    }

    #[Test]
    public function env_override_for_store_raw_sql_true(): void
    {
        $this->setEnv('STUDIO_STORE_RAW_SQL', 'true');

        $env = Environment::load(null);

        $result = StudioCollectorConfig::fromArray([
            'database' => ['store_raw_sql' => false],
        ], $env);

        self::assertTrue($result->storeRawSql);
    }

    #[Test]
    public function env_override_for_store_raw_sql_false(): void
    {
        $this->setEnv('STUDIO_STORE_RAW_SQL', 'false');

        $env = Environment::load(null);

        $result = StudioCollectorConfig::fromArray([
            'database' => ['store_raw_sql' => true],
        ], $env);

        self::assertFalse($result->storeRawSql);
    }

    #[Test]
    public function it_handles_partial_collector_data(): void
    {
        $env = Environment::load(null);

        $result = StudioCollectorConfig::fromArray([
            'http' => ['enabled' => false],
            'logs' => ['enabled' => false],
        ], $env);

        self::assertFalse($result->http);
        self::assertTrue($result->database);
        self::assertFalse($result->logs);
        self::assertTrue($result->exceptions);
        self::assertTrue($result->scheduler);
        self::assertTrue($result->featureFlags);
        self::assertTrue($result->queue);
        self::assertTrue($result->benchmark);
        self::assertTrue($result->runtime);
    }

    #[Test]
    public function it_handles_empty_sub_arrays(): void
    {
        $env = Environment::load(null);

        $result = StudioCollectorConfig::fromArray([
            'http' => [],
            'database' => [],
            'logs' => [],
            'exceptions' => [],
            'scheduler' => [],
            'feature_flags' => [],
            'queue' => [],
            'benchmark' => [],
            'runtime' => [],
        ], $env);

        self::assertTrue($result->http);
        self::assertTrue($result->database);
        self::assertTrue($result->logs);
        self::assertTrue($result->exceptions);
        self::assertTrue($result->scheduler);
        self::assertTrue($result->featureFlags);
        self::assertTrue($result->queue);
        self::assertTrue($result->benchmark);
        self::assertTrue($result->runtime);
        self::assertFalse($result->storeRawSql);
        self::assertFalse($result->redactTableNames);
    }
}
