<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Extension\Studio\Config\StudioRetentionConfig;

#[CoversClass(StudioRetentionConfig::class)]
final class StudioRetentionConfigTest extends TestCase
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
        $config = new StudioRetentionConfig();

        self::assertSame(7, $config->maxAgeDays);
        self::assertSame(500, $config->maxSizeMb);
        self::assertSame(24, $config->vacuumIntervalHours);
    }

    #[Test]
    public function it_creates_from_array_with_full_data(): void
    {
        $env = Environment::load(null);

        $result = StudioRetentionConfig::fromArray([
            'max_age_days' => 30,
            'max_size_mb' => 1024,
            'vacuum_interval_hours' => 12,
        ], $env);

        self::assertSame(30, $result->maxAgeDays);
        self::assertSame(1024, $result->maxSizeMb);
        self::assertSame(12, $result->vacuumIntervalHours);
    }

    #[Test]
    public function it_uses_defaults_when_array_is_empty(): void
    {
        $env = Environment::load(null);

        $result = StudioRetentionConfig::fromArray([], $env);

        self::assertSame(7, $result->maxAgeDays);
        self::assertSame(500, $result->maxSizeMb);
        self::assertSame(24, $result->vacuumIntervalHours);
    }

    #[Test]
    public function env_override_for_retention_days(): void
    {
        $this->setEnv('STUDIO_RETENTION_DAYS', '14');

        $env = Environment::load(null);

        $result = StudioRetentionConfig::fromArray([
            'max_age_days' => 30,
        ], $env);

        self::assertSame(14, $result->maxAgeDays);
    }

    #[Test]
    public function it_handles_numeric_string_values(): void
    {
        $env = Environment::load(null);

        $result = StudioRetentionConfig::fromArray([
            'max_age_days' => '15',
            'max_size_mb' => '2048',
            'vacuum_interval_hours' => '48',
        ], $env);

        self::assertSame(15, $result->maxAgeDays);
        self::assertSame(2048, $result->maxSizeMb);
        self::assertSame(48, $result->vacuumIntervalHours);
    }

    #[Test]
    public function it_falls_back_to_default_for_non_numeric_values(): void
    {
        $env = Environment::load(null);

        $result = StudioRetentionConfig::fromArray([
            'max_age_days' => 'invalid',
            'max_size_mb' => 'invalid',
            'vacuum_interval_hours' => 'invalid',
        ], $env);

        self::assertSame(7, $result->maxAgeDays);
        self::assertSame(500, $result->maxSizeMb);
        self::assertSame(24, $result->vacuumIntervalHours);
    }

    #[Test]
    public function env_override_takes_precedence_over_array(): void
    {
        $this->setEnv('STUDIO_RETENTION_DAYS', '3');

        $env = Environment::load(null);

        $result = StudioRetentionConfig::fromArray([
            'max_age_days' => 60,
        ], $env);

        self::assertSame(3, $result->maxAgeDays);
    }
}
