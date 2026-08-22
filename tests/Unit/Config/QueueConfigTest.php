<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\QueueConfig;
use Pulsar\Config\QueueDriverType;

#[CoversClass(QueueConfig::class)]
final class QueueConfigTest extends TestCase
{
    private Environment $environment;

    protected function setUp(): void
    {
        putenv('QUEUE_ENABLED');
        putenv('QUEUE_DRIVER');
        $this->environment = Environment::load();
    }

    protected function tearDown(): void
    {
        putenv('QUEUE_ENABLED');
        putenv('QUEUE_DRIVER');
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $data = [
            'enabled' => true,
            'driver' => 'database',
            'default_queue' => 'jobs',
            'worker' => [
                'max_jobs' => 500,
                'max_memory_mb' => 256,
                'time_limit_seconds' => 1800,
                'sleep_ms' => 500,
            ],
            'retry' => [
                'max_attempts' => 5,
                'base_delay_ms' => 2000,
                'max_delay_ms' => 120000,
                'multiplier' => 3.0,
            ],
            'dead_letter' => [
                'enabled' => false,
                'retention_days' => 7,
            ],
        ];

        $config = QueueConfig::fromArray($data, $this->environment);

        self::assertTrue($config->enabled);
        self::assertSame(QueueDriverType::Database, $config->driver);
        self::assertSame('jobs', $config->defaultQueue);
        self::assertSame(500, $config->workerMaxJobs);
        self::assertSame(256, $config->workerMaxMemoryMb);
        self::assertSame(1800, $config->workerTimeLimitSeconds);
        self::assertSame(500, $config->workerSleepMs);
        self::assertSame(5, $config->retryMaxAttempts);
        self::assertSame(2000, $config->retryBaseDelayMs);
        self::assertSame(120000, $config->retryMaxDelayMs);
        self::assertSame(3.0, $config->retryMultiplier);
        self::assertFalse($config->deadLetterEnabled);
        self::assertSame(7, $config->deadLetterRetentionDays);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = QueueConfig::fromArray([], $this->environment);

        self::assertFalse($config->enabled);
        self::assertSame(QueueDriverType::Sync, $config->driver);
        self::assertSame('default', $config->defaultQueue);
        self::assertSame(1000, $config->workerMaxJobs);
        self::assertSame(256, $config->workerMaxMemoryMb);
        self::assertSame(3600, $config->workerTimeLimitSeconds);
        self::assertSame(1000, $config->workerSleepMs);
        self::assertSame(3, $config->retryMaxAttempts);
        self::assertSame(1000, $config->retryBaseDelayMs);
        self::assertSame(60000, $config->retryMaxDelayMs);
        self::assertSame(2.0, $config->retryMultiplier);
        self::assertTrue($config->deadLetterEnabled);
        self::assertSame(30, $config->deadLetterRetentionDays);
        self::assertFalse($config->encryptPayloads);
        self::assertFalse($config->enforceEffectClassification);
        self::assertFalse($config->preventDuplicates);
        self::assertSame(300, $config->preventDuplicatesTtlSeconds);
        self::assertFalse($config->rateLimitEnabled);
        self::assertSame(1, $config->rateLimitTtlSeconds);
        self::assertSame(0, $config->rateLimitTimeoutMs);
    }

    #[Test]
    public function fromArrayParsesTheMiddlewareBlock(): void
    {
        $config = QueueConfig::fromArray([
            'middleware' => [
                'encrypt_payloads' => true,
                'enforce_effect_classification' => true,
                'prevent_duplicates' => ['enabled' => true, 'ttl_seconds' => 60],
                'rate_limit' => ['enabled' => true, 'ttl_seconds' => 2, 'timeout_ms' => 500],
            ],
        ], $this->environment);

        self::assertTrue($config->encryptPayloads);
        self::assertTrue($config->enforceEffectClassification);
        self::assertTrue($config->preventDuplicates);
        self::assertSame(60, $config->preventDuplicatesTtlSeconds);
        self::assertTrue($config->rateLimitEnabled);
        self::assertSame(2, $config->rateLimitTtlSeconds);
        self::assertSame(500, $config->rateLimitTimeoutMs);
    }

    #[Test]
    public function environmentVariableOverridesEnabledToTrue(): void
    {
        putenv('QUEUE_ENABLED=true');
        $environment = Environment::load();

        $config = QueueConfig::fromArray([
            'enabled' => false,
        ], $environment);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function environmentVariableOverridesEnabledToFalse(): void
    {
        putenv('QUEUE_ENABLED=false');
        $environment = Environment::load();

        $config = QueueConfig::fromArray([
            'enabled' => true,
        ], $environment);

        self::assertFalse($config->enabled);
    }

    #[Test]
    public function environmentVariableOverridesDriver(): void
    {
        putenv('QUEUE_DRIVER=memory');
        $environment = Environment::load();

        $config = QueueConfig::fromArray([
            'driver' => 'sync',
        ], $environment);

        self::assertSame(QueueDriverType::Memory, $config->driver);
    }

    #[Test]
    public function configArrayEnabledUsedWhenNoEnvironmentVariable(): void
    {
        $config = QueueConfig::fromArray([
            'enabled' => true,
        ], $this->environment);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function constructorDefaults(): void
    {
        $config = new QueueConfig();

        self::assertFalse($config->enabled);
        self::assertSame(QueueDriverType::Sync, $config->driver);
        self::assertSame('default', $config->defaultQueue);
        self::assertSame(1000, $config->workerMaxJobs);
        self::assertSame(256, $config->workerMaxMemoryMb);
        self::assertSame(3600, $config->workerTimeLimitSeconds);
        self::assertSame(1000, $config->workerSleepMs);
        self::assertSame(3, $config->retryMaxAttempts);
        self::assertSame(1000, $config->retryBaseDelayMs);
        self::assertSame(60000, $config->retryMaxDelayMs);
        self::assertSame(2.0, $config->retryMultiplier);
        self::assertTrue($config->deadLetterEnabled);
        self::assertSame(30, $config->deadLetterRetentionDays);
    }
}
