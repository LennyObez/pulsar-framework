<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\TracingConfig;

#[CoversClass(TracingConfig::class)]
final class TracingConfigTest extends TestCase
{
    #[Test]
    public function constructorDefaults(): void
    {
        $config = new TracingConfig();

        self::assertFalse($config->enabled);
        self::assertSame(0.1, $config->samplingRate);
    }

    #[Test]
    public function fromArrayWithAllFields(): void
    {
        $config = TracingConfig::fromArray([
            'enabled' => true,
            'sampling_rate' => 0.5,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(0.5, $config->samplingRate);
    }

    #[Test]
    public function fromArrayWithIntSamplingRate(): void
    {
        $config = TracingConfig::fromArray([
            'sampling_rate' => 1,
        ]);

        self::assertSame(1.0, $config->samplingRate);
    }

    #[Test]
    public function fromArrayWithNumericStringSamplingRate(): void
    {
        $config = TracingConfig::fromArray([
            'sampling_rate' => '0.75',
        ]);

        self::assertSame(0.75, $config->samplingRate);
    }

    #[Test]
    public function fromArrayWithNonNumericSamplingRateUsesDefault(): void
    {
        $config = TracingConfig::fromArray([
            'sampling_rate' => 'invalid',
        ]);

        self::assertSame(0.1, $config->samplingRate);
    }

    #[Test]
    public function fromArrayWithEmptyData(): void
    {
        $config = TracingConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame(0.1, $config->samplingRate);
    }
}
