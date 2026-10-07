<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\CmsCacheConfig;

#[CoversClass(CmsCacheConfig::class)]
final class CmsCacheConfigTest extends TestCase
{
    #[Test]
    public function defaultValuesAreReasonable(): void
    {
        $config = new CmsCacheConfig();

        self::assertSame(3600, $config->pageCacheTtlSeconds);
        self::assertTrue($config->stampedeProtection);
        self::assertSame(1.0, $config->earlyRecomputeBeta);
        self::assertSame(300, $config->staleGracePeriodSeconds);
        self::assertSame(5, $config->lockTimeoutSeconds);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = CmsCacheConfig::fromArray([
            'page_cache_ttl_seconds' => 7200,
            'stampede_protection' => false,
            'early_recompute_beta' => 2.5,
            'stale_grace_period_seconds' => 600,
            'lock_timeout_seconds' => 10,
        ]);

        self::assertSame(7200, $config->pageCacheTtlSeconds);
        self::assertFalse($config->stampedeProtection);
        self::assertSame(2.5, $config->earlyRecomputeBeta);
        self::assertSame(600, $config->staleGracePeriodSeconds);
        self::assertSame(10, $config->lockTimeoutSeconds);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = CmsCacheConfig::fromArray([]);

        self::assertSame(3600, $config->pageCacheTtlSeconds);
        self::assertTrue($config->stampedeProtection);
        self::assertSame(1.0, $config->earlyRecomputeBeta);
    }

    #[Test]
    public function fromArrayIgnoresWrongTypes(): void
    {
        $config = CmsCacheConfig::fromArray([
            'page_cache_ttl_seconds' => 'fast',
            'stampede_protection' => 'yes',
            'early_recompute_beta' => 'aggressive',
        ]);

        // Non-int for page_cache_ttl_seconds → default 3600
        self::assertSame(3600, $config->pageCacheTtlSeconds);
        // Non-bool for stampede_protection → default true
        self::assertTrue($config->stampedeProtection);
        // Non-numeric for early_recompute_beta → default 1.0
        self::assertSame(1.0, $config->earlyRecomputeBeta);
    }

    #[Test]
    public function customConstructorValues(): void
    {
        $config = new CmsCacheConfig(
            pageCacheTtlSeconds: 0,
            stampedeProtection: false,
            earlyRecomputeBeta: 1.5,
            staleGracePeriodSeconds: 0,
            lockTimeoutSeconds: 1,
        );

        self::assertSame(0, $config->pageCacheTtlSeconds);
        self::assertFalse($config->stampedeProtection);
        self::assertSame(1.5, $config->earlyRecomputeBeta);
        self::assertSame(0, $config->staleGracePeriodSeconds);
        self::assertSame(1, $config->lockTimeoutSeconds);
    }
}
