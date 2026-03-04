<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Config\CardinalityConfig;

#[CoversClass(CardinalityConfig::class)]
final class CardinalityConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new CardinalityConfig();

        self::assertSame(1000, $config->maxAttributeKeys);
        self::assertSame(2000, $config->maxMetricSeries);
        self::assertTrue($config->normalizeUrls);
    }

    #[Test]
    public function customValues(): void
    {
        $config = new CardinalityConfig(
            maxAttributeKeys: 500,
            maxMetricSeries: 1000,
            normalizeUrls: false,
        );

        self::assertSame(500, $config->maxAttributeKeys);
        self::assertSame(1000, $config->maxMetricSeries);
        self::assertFalse($config->normalizeUrls);
    }

    #[Test]
    public function fromArrayWithValidData(): void
    {
        $config = CardinalityConfig::fromArray([
            'max_attribute_keys' => 750,
            'max_metric_series' => 1500,
            'normalize_urls' => false,
        ]);

        self::assertSame(750, $config->maxAttributeKeys);
        self::assertSame(1500, $config->maxMetricSeries);
        self::assertFalse($config->normalizeUrls);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = CardinalityConfig::fromArray([]);

        self::assertSame(1000, $config->maxAttributeKeys);
        self::assertSame(2000, $config->maxMetricSeries);
        self::assertTrue($config->normalizeUrls);
    }

    #[Test]
    public function fromArrayWithInvalidTypesUsesDefaults(): void
    {
        $config = CardinalityConfig::fromArray([
            'max_attribute_keys' => 'bad',
            'max_metric_series' => [],
            'normalize_urls' => 'yes',
        ]);

        self::assertSame(1000, $config->maxAttributeKeys);
        self::assertSame(2000, $config->maxMetricSeries);
        self::assertTrue($config->normalizeUrls);
    }
}
