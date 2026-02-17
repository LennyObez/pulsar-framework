<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Config\SamplerConfig;
use Pulsar\Extension\OpenTelemetry\Config\SamplerType;

#[CoversClass(SamplerConfig::class)]
final class SamplerConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new SamplerConfig();

        self::assertSame(SamplerType::ParentBased, $config->type);
        self::assertSame(1.0, $config->probability);
        self::assertSame(100.0, $config->ratePerSecond);
    }

    #[Test]
    public function customValues(): void
    {
        $config = new SamplerConfig(
            type: SamplerType::Probability,
            probability: 0.5,
            ratePerSecond: 50.0,
        );

        self::assertSame(SamplerType::Probability, $config->type);
        self::assertSame(0.5, $config->probability);
        self::assertSame(50.0, $config->ratePerSecond);
    }

    #[Test]
    public function fromArrayWithValidData(): void
    {
        $config = SamplerConfig::fromArray([
            'type' => 'always',
            'probability' => 0.75,
            'rate_per_second' => 200.0,
        ]);

        self::assertSame(SamplerType::Always, $config->type);
        self::assertSame(0.75, $config->probability);
        self::assertSame(200.0, $config->ratePerSecond);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = SamplerConfig::fromArray([]);

        self::assertSame(SamplerType::ParentBased, $config->type);
        self::assertSame(1.0, $config->probability);
        self::assertSame(100.0, $config->ratePerSecond);
    }

    #[Test]
    public function fromArrayWithInvalidTypeStringFallsBackToDefault(): void
    {
        $config = SamplerConfig::fromArray([
            'type' => 'nonexistent_sampler',
        ]);

        self::assertSame(SamplerType::ParentBased, $config->type);
    }

    #[Test]
    public function fromArrayWithInvalidTypesUsesDefaults(): void
    {
        $config = SamplerConfig::fromArray([
            'type' => 42,
            'probability' => 'high',
            'rate_per_second' => false,
        ]);

        self::assertSame(SamplerType::ParentBased, $config->type);
        self::assertSame(1.0, $config->probability);
        self::assertSame(100.0, $config->ratePerSecond);
    }

    #[Test]
    public function fromArrayAcceptsIntegerForProbability(): void
    {
        $config = SamplerConfig::fromArray([
            'probability' => 1,
        ]);

        self::assertSame(1.0, $config->probability);
    }

    #[Test]
    public function fromArrayAcceptsIntegerForRatePerSecond(): void
    {
        $config = SamplerConfig::fromArray([
            'rate_per_second' => 50,
        ]);

        self::assertSame(50.0, $config->ratePerSecond);
    }
}
