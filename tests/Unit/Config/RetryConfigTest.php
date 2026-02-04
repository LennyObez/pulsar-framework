<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\RetryConfig;

#[CoversClass(RetryConfig::class)]
final class RetryConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithFullData(): void
    {
        $config = RetryConfig::fromArray([
            'max_attempts' => 5,
            'base_delay_ms' => 200,
            'max_delay_ms' => 10000,
            'multiplier' => 3.0,
            'jitter' => false,
        ]);

        self::assertSame(5, $config->maxAttempts);
        self::assertSame(200, $config->baseDelayMs);
        self::assertSame(10000, $config->maxDelayMs);
        self::assertSame(3.0, $config->multiplier);
        self::assertFalse($config->jitter);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = RetryConfig::fromArray([]);

        self::assertSame(3, $config->maxAttempts);
        self::assertSame(100, $config->baseDelayMs);
        self::assertSame(5000, $config->maxDelayMs);
        self::assertSame(2.0, $config->multiplier);
        self::assertTrue($config->jitter);
    }
}
