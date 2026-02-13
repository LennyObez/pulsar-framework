<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Core\BootProfile;
use Pulsar\Core\Kernel;

#[CoversClass(BootProfile::class)]
final class BootProfileTest extends TestCase
{
    #[Test]
    public function constructionPreservesValues(): void
    {
        $profile = new BootProfile(
            totalUs: 5000,
            cacheLoadUs: 1000,
            configUs: 2000,
            extensionRegisterUs: 500,
            extensionBootUs: 1500,
            cacheHit: true,
            routesCached: true,
        );

        self::assertSame(5000, $profile->totalUs);
        self::assertSame(1000, $profile->cacheLoadUs);
        self::assertSame(2000, $profile->configUs);
        self::assertSame(500, $profile->extensionRegisterUs);
        self::assertSame(1500, $profile->extensionBootUs);
        self::assertTrue($profile->cacheHit);
        self::assertTrue($profile->routesCached);
    }

    #[Test]
    public function toArrayReturnsExpectedShape(): void
    {
        $profile = new BootProfile(
            totalUs: 5000,
            cacheLoadUs: 1000,
            configUs: 2000,
            extensionRegisterUs: 500,
            extensionBootUs: 1500,
            cacheHit: false,
            routesCached: false,
        );

        $array = $profile->toArray();

        self::assertSame([
            'total_us' => 5000,
            'cache_load_us' => 1000,
            'config_us' => 2000,
            'extension_register_us' => 500,
            'extension_boot_us' => 1500,
            'compiler_pass_us' => 0,
            'cache_hit' => false,
            'routes_cached' => false,
        ], $array);
    }

    #[Test]
    public function zeroDurationsAreValid(): void
    {
        $profile = new BootProfile(
            totalUs: 0,
            cacheLoadUs: 0,
            configUs: 0,
            extensionRegisterUs: 0,
            extensionBootUs: 0,
            cacheHit: false,
            routesCached: false,
        );

        self::assertSame(0, $profile->totalUs);
        self::assertSame(0, $profile->cacheLoadUs);
    }

    #[Test]
    public function kernelExposesBootProfileAfterBoot(): void
    {
        $kernel = new Kernel();

        self::assertNull($kernel->bootProfile());

        $kernel->boot();

        $profile = $kernel->bootProfile();
        self::assertNotNull($profile);
        self::assertGreaterThanOrEqual(0, $profile->totalUs);
        self::assertFalse($profile->cacheHit);
        self::assertFalse($profile->routesCached);
    }
}
