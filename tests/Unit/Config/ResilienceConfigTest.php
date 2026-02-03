<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CircuitBreakerConfig;
use Pulsar\Config\Environment;
use Pulsar\Config\HealthCheckConfig;
use Pulsar\Config\ResilienceConfig;
use Pulsar\Config\RetryConfig;

#[CoversClass(ResilienceConfig::class)]
final class ResilienceConfigTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('RESILIENCE_ENABLED');
    }

    protected function tearDown(): void
    {
        putenv('RESILIENCE_ENABLED');
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $env = Environment::load();

        $config = ResilienceConfig::fromArray([
            'enabled' => true,
            'retry' => [
                'max_attempts' => 5,
                'base_delay_ms' => 200,
                'max_delay_ms' => 10000,
                'multiplier' => 3.0,
                'jitter' => false,
            ],
            'circuit_breaker' => [
                'failure_threshold' => 10,
                'success_threshold' => 3,
                'open_timeout_seconds' => 60,
                'sample_window_seconds' => 120,
            ],
            'health_check' => [
                'interval_seconds' => 15,
                'timeout_seconds' => 10,
            ],
        ], $env);

        self::assertTrue($config->enabled);
        self::assertSame(5, $config->retry->maxAttempts);
        self::assertSame(200, $config->retry->baseDelayMs);
        self::assertSame(10000, $config->retry->maxDelayMs);
        self::assertSame(3.0, $config->retry->multiplier);
        self::assertFalse($config->retry->jitter);
        self::assertSame(10, $config->circuitBreaker->failureThreshold);
        self::assertSame(3, $config->circuitBreaker->successThreshold);
        self::assertSame(60, $config->circuitBreaker->openTimeoutSeconds);
        self::assertSame(120, $config->circuitBreaker->sampleWindowSeconds);
        self::assertSame(15, $config->healthCheck->intervalSeconds);
        self::assertSame(10, $config->healthCheck->timeoutSeconds);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $env = Environment::load();

        $config = ResilienceConfig::fromArray([], $env);

        self::assertFalse($config->enabled);
        self::assertInstanceOf(RetryConfig::class, $config->retry);
        self::assertInstanceOf(CircuitBreakerConfig::class, $config->circuitBreaker);
        self::assertInstanceOf(HealthCheckConfig::class, $config->healthCheck);
        self::assertSame(3, $config->retry->maxAttempts);
        self::assertSame(5, $config->circuitBreaker->failureThreshold);
        self::assertSame(30, $config->healthCheck->intervalSeconds);
    }

    #[Test]
    public function environmentVariableOverridesEnabledField(): void
    {
        putenv('RESILIENCE_ENABLED=true');

        $env = Environment::load();
        $config = ResilienceConfig::fromArray(['enabled' => false], $env);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function subConfigsAreProperlyComposed(): void
    {
        $env = Environment::load();

        $config = ResilienceConfig::fromArray([
            'retry' => ['max_attempts' => 7],
            'circuit_breaker' => ['failure_threshold' => 8],
            'health_check' => ['interval_seconds' => 45],
        ], $env);

        self::assertSame(7, $config->retry->maxAttempts);
        self::assertSame(8, $config->circuitBreaker->failureThreshold);
        self::assertSame(45, $config->healthCheck->intervalSeconds);

        // Other fields should retain defaults
        self::assertSame(100, $config->retry->baseDelayMs);
        self::assertSame(2, $config->circuitBreaker->successThreshold);
        self::assertSame(5, $config->healthCheck->timeoutSeconds);
    }
}
