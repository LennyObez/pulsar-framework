<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Config\RateLimitConfig;

final class RateLimitConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new RateLimitConfig();

        self::assertSame(30, $config->maxEventsPerIpPerMinute);
        self::assertSame(5, $config->burst);
    }

    #[Test]
    public function fromArrayWithValidNumericValues(): void
    {
        $config = RateLimitConfig::fromArray([
            'max_events_per_ip_per_minute' => 100,
            'burst' => 20,
        ]);

        self::assertSame(100, $config->maxEventsPerIpPerMinute);
        self::assertSame(20, $config->burst);
    }

    #[Test]
    public function fromArrayWithStringNumericValues(): void
    {
        $config = RateLimitConfig::fromArray([
            'max_events_per_ip_per_minute' => '50',
            'burst' => '15',
        ]);

        self::assertSame(50, $config->maxEventsPerIpPerMinute);
        self::assertSame(15, $config->burst);
    }

    #[Test]
    public function fromArrayWithNonNumericFallsBackToDefaults(): void
    {
        $config = RateLimitConfig::fromArray([
            'max_events_per_ip_per_minute' => 'not-a-number',
            'burst' => [],
        ]);

        self::assertSame(30, $config->maxEventsPerIpPerMinute);
        self::assertSame(5, $config->burst);
    }
}
