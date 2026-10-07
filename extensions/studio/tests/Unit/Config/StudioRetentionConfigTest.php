<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Extension\Studio\Config\StudioRetentionConfig;

final class StudioRetentionConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new StudioRetentionConfig();

        self::assertSame(7, $config->maxAgeDays);
        self::assertSame(500, $config->maxSizeMb);
        self::assertSame(24, $config->vacuumIntervalHours);
    }

    #[Test]
    public function fromArrayReadsMaxAgeDays(): void
    {
        $env = Environment::load();
        $config = StudioRetentionConfig::fromArray(['max_age_days' => 30], $env);

        self::assertSame(30, $config->maxAgeDays);
    }

    #[Test]
    public function fromArrayReadsMaxSizeMb(): void
    {
        $env = Environment::load();
        $config = StudioRetentionConfig::fromArray(['max_size_mb' => 1024], $env);

        self::assertSame(1024, $config->maxSizeMb);
    }

    #[Test]
    public function fromArrayReadsVacuumInterval(): void
    {
        $env = Environment::load();
        $config = StudioRetentionConfig::fromArray(['vacuum_interval_hours' => 12], $env);

        self::assertSame(12, $config->vacuumIntervalHours);
    }
}
