<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Config\RetentionConfig;

#[CoversClass(RetentionConfig::class)]
final class RetentionConfigTest extends TestCase
{
    #[Test]
    public function defaultConstruction(): void
    {
        $config = new RetentionConfig();

        self::assertSame(90, $config->rawDays);
        self::assertSame(730, $config->aggregatedDays);
        self::assertSame(48, $config->hourlyHours);
    }

    #[Test]
    public function fromArrayWithValues(): void
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
    public function fromArrayWithEmptyData(): void
    {
        $config = RetentionConfig::fromArray([]);

        self::assertSame(90, $config->rawDays);
        self::assertSame(730, $config->aggregatedDays);
        self::assertSame(48, $config->hourlyHours);
    }

    #[Test]
    public function fromArrayWithNumericStrings(): void
    {
        $config = RetentionConfig::fromArray([
            'raw_days' => '60',
            'aggregated_days' => '365',
            'hourly_hours' => '72',
        ]);

        self::assertSame(60, $config->rawDays);
        self::assertSame(365, $config->aggregatedDays);
        self::assertSame(72, $config->hourlyHours);
    }

    #[Test]
    public function fromArrayCastsNonNumericValuesViaIntCast(): void
    {
        $config = RetentionConfig::fromArray([
            'raw_days' => 'not-a-number',
            'aggregated_days' => null,
            'hourly_hours' => [],
        ]);

        // (int) 'not-a-number' = 0 (string present, ?? does not trigger)
        self::assertSame(0, $config->rawDays);
        // null ?? 730 = 730 (null coalescing triggers for null values)
        self::assertSame(730, $config->aggregatedDays);
        // (int) [] = 0 (array present, ?? does not trigger)
        self::assertSame(0, $config->hourlyHours);
    }
}
