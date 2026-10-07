<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Htmx;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Htmx\HtmxConfig;

#[CoversClass(HtmxConfig::class)]
final class HtmxConfigTest extends TestCase
{
    #[Test]
    public function defaults(): void
    {
        $config = new HtmxConfig();

        self::assertSame('px', $config->attributePrefix);
        self::assertSame(0, $config->defaultSwapDelayMs);
        self::assertSame(20, $config->defaultSettleDelayMs);
        self::assertTrue($config->includeIndicatorStyles);
        self::assertTrue($config->historyCacheEnabled);
        self::assertSame(10, $config->historyCacheSize);
        self::assertTrue($config->selfRequestsOnly);
        self::assertSame('X-CSRF-Token', $config->csrfHeaderName);
    }

    #[Test]
    public function from_array_with_values(): void
    {
        $config = HtmxConfig::fromArray([
            'attribute_prefix' => 'hx',
            'default_swap_delay_ms' => 100,
            'default_settle_delay_ms' => 50,
            'include_indicator_styles' => false,
            'history_cache_enabled' => false,
            'history_cache_size' => 5,
            'self_requests_only' => false,
            'csrf_header_name' => 'X-Custom-CSRF',
        ]);

        self::assertSame('hx', $config->attributePrefix);
        self::assertSame(100, $config->defaultSwapDelayMs);
        self::assertSame(50, $config->defaultSettleDelayMs);
        self::assertFalse($config->includeIndicatorStyles);
        self::assertFalse($config->historyCacheEnabled);
        self::assertSame(5, $config->historyCacheSize);
        self::assertFalse($config->selfRequestsOnly);
        self::assertSame('X-Custom-CSRF', $config->csrfHeaderName);
    }

    #[Test]
    public function from_array_falls_back_to_defaults_for_invalid_types(): void
    {
        $config = HtmxConfig::fromArray([
            'attribute_prefix' => 123,
            'default_swap_delay_ms' => 'not-int',
            'self_requests_only' => 'not-bool',
        ]);

        self::assertSame('px', $config->attributePrefix);
        self::assertSame(0, $config->defaultSwapDelayMs);
        self::assertTrue($config->selfRequestsOnly);
    }

    #[Test]
    public function from_array_with_empty_array(): void
    {
        $config = HtmxConfig::fromArray([]);

        self::assertSame('px', $config->attributePrefix);
    }
}
