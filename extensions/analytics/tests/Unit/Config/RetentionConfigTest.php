<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Config\RetentionConfig;

final class RetentionConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new RetentionConfig();

        self::assertSame(90, $config->rawDays);
        self::assertSame(730, $config->aggregatedDays);
        self::assertSame(48, $config->hourlyHours);
    }

    #[Test]
    public function fromArrayWithCustomValues(): void
    {
        $config = RetentionConfig::fromArray([
            'raw_days' => 30,
            'aggregated_days' => 365,
            'hourly_hours' => 24,
        ]);

        self::assertSame(30, $config->rawDays);
        self::assertSame(365, $config->aggregatedDays);
        self::assertSame(24, $config->hourlyHours);
    }

    #[Test]
    public function fromArrayWithNonNumericReturnsZeroForPresentKeys(): void
    {
        $config = RetentionConfig::fromArray([
            'raw_days' => 'invalid',
            'aggregated_days' => null,
            'hourly_hours' => false,
        ]);

        // Non-numeric present values return 0; null returns default
        self::assertSame(0, $config->rawDays);
        self::assertSame(730, $config->aggregatedDays);
        self::assertSame(0, $config->hourlyHours);
    }

    #[Test]
    public function fromArrayWithEmptyDataUsesDefaults(): void
    {
        $config = RetentionConfig::fromArray([]);

        self::assertSame(90, $config->rawDays);
        self::assertSame(730, $config->aggregatedDays);
        self::assertSame(48, $config->hourlyHours);
    }
}
