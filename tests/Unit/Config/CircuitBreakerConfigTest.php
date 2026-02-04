<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CircuitBreakerConfig;

#[CoversClass(CircuitBreakerConfig::class)]
final class CircuitBreakerConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithFullData(): void
    {
        $config = CircuitBreakerConfig::fromArray([
            'failure_threshold' => 10,
            'success_threshold' => 3,
            'open_timeout_seconds' => 60,
            'sample_window_seconds' => 120,
        ]);

        self::assertSame(10, $config->failureThreshold);
        self::assertSame(3, $config->successThreshold);
        self::assertSame(60, $config->openTimeoutSeconds);
        self::assertSame(120, $config->sampleWindowSeconds);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = CircuitBreakerConfig::fromArray([]);

        self::assertSame(5, $config->failureThreshold);
        self::assertSame(2, $config->successThreshold);
        self::assertSame(30, $config->openTimeoutSeconds);
        self::assertSame(60, $config->sampleWindowSeconds);
    }
}
