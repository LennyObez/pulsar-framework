<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Config\SamplerConfig;
use Pulsar\Extension\Observability\Config\SamplerType;

#[CoversClass(SamplerConfig::class)]
final class SamplerConfigDeepTest extends TestCase
{
    #[Test]
    public function nonStringTypeDefaultsToParentBased(): void
    {
        $config = SamplerConfig::fromArray(['type' => 123]);

        self::assertSame(SamplerType::ParentBased, $config->type);
    }

    #[Test]
    public function nonNumericProbabilityDefaultsToOne(): void
    {
        $config = SamplerConfig::fromArray(['probability' => 'not-a-number']);

        self::assertSame(1.0, $config->probability);
    }

    #[Test]
    public function nonNumericRateDefaultsToHundred(): void
    {
        $config = SamplerConfig::fromArray(['rate_per_second' => 'fast']);

        self::assertSame(100.0, $config->ratePerSecond);
    }

    #[Test]
    public function integerProbabilityIsCastToFloat(): void
    {
        $config = SamplerConfig::fromArray(['probability' => 1]);

        self::assertSame(1.0, $config->probability);
    }

    #[Test]
    public function integerRateIsCastToFloat(): void
    {
        $config = SamplerConfig::fromArray(['rate_per_second' => 50]);

        self::assertSame(50.0, $config->ratePerSecond);
    }

    #[Test]
    public function allSamplerTypesCanBeUsed(): void
    {
        foreach (['always', 'never', 'probability', 'rate_limited', 'parent_based'] as $type) {
            $config = SamplerConfig::fromArray(['type' => $type]);
            self::assertSame($type, $config->type->value);
        }
    }

    #[Test]
    public function boolTypeDefaultsToParentBased(): void
    {
        $config = SamplerConfig::fromArray(['type' => true]);

        self::assertSame(SamplerType::ParentBased, $config->type);
    }

    #[Test]
    public function arrayProbabilityDefaultsToOne(): void
    {
        $config = SamplerConfig::fromArray(['probability' => [0.5]]);

        self::assertSame(1.0, $config->probability);
    }

    #[Test]
    public function boolRateDefaultsToHundred(): void
    {
        $config = SamplerConfig::fromArray(['rate_per_second' => true]);

        self::assertSame(100.0, $config->ratePerSecond);
    }
}
