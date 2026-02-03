<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\RuntimeConfig;

#[CoversClass(RuntimeConfig::class)]
final class RuntimeConfigTest extends TestCase
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
        $config = new RuntimeConfig();

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(8080, $config->port);
        self::assertSame(10_000, $config->maxRequests);
        self::assertSame(256, $config->memoryThresholdMb);
        self::assertSame(7200, $config->timeLimitSeconds);
        self::assertTrue($config->keepAlive);
        self::assertSame(15, $config->keepAliveTimeout);
        self::assertSame(15, $config->headerTimeoutSeconds);
        self::assertSame(60, $config->bodyTimeoutSeconds);
        self::assertSame(0, $config->fiberConcurrency);
        self::assertSame(8192, $config->maxHeaderSize);
        self::assertSame(10_485_760, $config->maxBodySize);
        self::assertTrue($config->addDateHeader);
    }

    #[Test]
    public function it_creates_from_array(): void
    {
        $env = Environment::load(null);

        $config = RuntimeConfig::fromArray([
            'host' => '0.0.0.0',
            'port' => 9090,
            'max_requests' => 5000,
            'memory_threshold_mb' => 512,
            'time_limit_seconds' => 3600,
            'keep_alive' => false,
            'keep_alive_timeout' => 30,
            'header_timeout_seconds' => 10,
            'body_timeout_seconds' => 120,
            'fiber_concurrency' => 32,
            'max_header_size' => 16384,
            'max_body_size' => 20_971_520,
            'add_date_header' => false,
        ], $env);

        self::assertSame('0.0.0.0', $config->host);
        self::assertSame(9090, $config->port);
        self::assertSame(5000, $config->maxRequests);
        self::assertSame(512, $config->memoryThresholdMb);
        self::assertSame(3600, $config->timeLimitSeconds);
        self::assertFalse($config->keepAlive);
        self::assertSame(30, $config->keepAliveTimeout);
        self::assertSame(10, $config->headerTimeoutSeconds);
        self::assertSame(120, $config->bodyTimeoutSeconds);
        self::assertSame(32, $config->fiberConcurrency);
        self::assertSame(16384, $config->maxHeaderSize);
        self::assertSame(20_971_520, $config->maxBodySize);
        self::assertFalse($config->addDateHeader);
    }

    #[Test]
    public function it_applies_env_overrides(): void
    {
        $this->setEnv('RUNTIME_HOST', '10.0.0.1');
        $this->setEnv('RUNTIME_PORT', '3000');
        $this->setEnv('RUNTIME_MAX_REQUESTS', '500');
        $this->setEnv('RUNTIME_MEMORY_THRESHOLD_MB', '128');
        $this->setEnv('RUNTIME_TIME_LIMIT_SECONDS', '1800');
        $this->setEnv('RUNTIME_FIBER_CONCURRENCY', '16');

        $env = Environment::load(null);
        $config = RuntimeConfig::fromArray([], $env);

        self::assertSame('10.0.0.1', $config->host);
        self::assertSame(3000, $config->port);
        self::assertSame(500, $config->maxRequests);
        self::assertSame(128, $config->memoryThresholdMb);
        self::assertSame(1800, $config->timeLimitSeconds);
        self::assertSame(16, $config->fiberConcurrency);
    }

    #[Test]
    public function env_overrides_take_precedence_over_array(): void
    {
        $this->setEnv('RUNTIME_PORT', '4000');

        $env = Environment::load(null);
        $config = RuntimeConfig::fromArray(['port' => 8080], $env);

        self::assertSame(4000, $config->port);
    }

    #[Test]
    public function it_uses_array_defaults_when_no_env(): void
    {
        $env = Environment::load(null);
        $config = RuntimeConfig::fromArray([], $env);

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(8080, $config->port);
    }
}
