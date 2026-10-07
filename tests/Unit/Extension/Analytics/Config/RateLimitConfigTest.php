<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Config\RateLimitConfig;

#[CoversClass(RateLimitConfig::class)]
final class RateLimitConfigTest extends TestCase
{
    #[Test]
    public function defaultConstruction(): void
    {
        $config = new RateLimitConfig();

        self::assertSame(30, $config->maxEventsPerIpPerMinute);
        self::assertSame(5, $config->burst);
    }

    #[Test]
    public function fromArrayWithValues(): void
    {
        $config = RateLimitConfig::fromArray([
            'max_events_per_ip_per_minute' => 60,
            'burst' => 10,
        ]);

        self::assertSame(60, $config->maxEventsPerIpPerMinute);
        self::assertSame(10, $config->burst);
    }

    #[Test]
    public function fromArrayWithEmptyData(): void
    {
        $config = RateLimitConfig::fromArray([]);

        self::assertSame(30, $config->maxEventsPerIpPerMinute);
        self::assertSame(5, $config->burst);
    }

    #[Test]
    public function fromArrayWithNumericStrings(): void
    {
        $config = RateLimitConfig::fromArray([
            'max_events_per_ip_per_minute' => '100',
            'burst' => '20',
        ]);

        self::assertSame(100, $config->maxEventsPerIpPerMinute);
        self::assertSame(20, $config->burst);
    }

    #[Test]
    public function fromArrayCastsNonNumericValuesViaIntCast(): void
    {
        $config = RateLimitConfig::fromArray([
            'max_events_per_ip_per_minute' => 'fast',
            'burst' => null,
        ]);

        // (int) 'fast' = 0 (string present, ?? does not trigger)
        self::assertSame(0, $config->maxEventsPerIpPerMinute);
        // null ?? 5 = 5 (null coalescing triggers for null values)
        self::assertSame(5, $config->burst);
    }
}
