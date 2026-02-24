<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Extension\Studio\Security\ProductionSafetyMode;

#[CoversClass(ProductionSafetyMode::class)]
final class ProductionSafetyModeTest extends TestCase
{
    #[Test]
    public function productionDisallowsDrillDown(): void
    {
        $mode = new ProductionSafetyMode(EnvironmentMode::Production);

        self::assertFalse($mode->allowDrillDown());
    }

    #[Test]
    public function localAllowsDrillDown(): void
    {
        $mode = new ProductionSafetyMode(EnvironmentMode::Local);

        self::assertTrue($mode->allowDrillDown());
    }

    #[Test]
    public function stagingAllowsDrillDown(): void
    {
        $mode = new ProductionSafetyMode(EnvironmentMode::Staging);

        self::assertTrue($mode->allowDrillDown());
    }

    #[Test]
    public function onlyLocalAllowsStackTraces(): void
    {
        self::assertTrue(new ProductionSafetyMode(EnvironmentMode::Local)->allowStackTraces());
        self::assertFalse(new ProductionSafetyMode(EnvironmentMode::Staging)->allowStackTraces());
        self::assertFalse(new ProductionSafetyMode(EnvironmentMode::Production)->allowStackTraces());
    }

    #[Test]
    public function productionDisallowsSse(): void
    {
        $mode = new ProductionSafetyMode(EnvironmentMode::Production);

        self::assertFalse($mode->allowSse());
    }

    #[Test]
    public function localAndStagingAllowSse(): void
    {
        self::assertTrue(new ProductionSafetyMode(EnvironmentMode::Local)->allowSse());
        self::assertTrue(new ProductionSafetyMode(EnvironmentMode::Staging)->allowSse());
    }

    #[Test]
    public function onlyLocalAllowsRawPayload(): void
    {
        self::assertTrue(new ProductionSafetyMode(EnvironmentMode::Local)->allowRawPayload());
        self::assertFalse(new ProductionSafetyMode(EnvironmentMode::Staging)->allowRawPayload());
        self::assertFalse(new ProductionSafetyMode(EnvironmentMode::Production)->allowRawPayload());
    }

    #[Test]
    public function productionDisallowsApi(): void
    {
        self::assertFalse(new ProductionSafetyMode(EnvironmentMode::Production)->allowApi());
        self::assertTrue(new ProductionSafetyMode(EnvironmentMode::Local)->allowApi());
        self::assertTrue(new ProductionSafetyMode(EnvironmentMode::Staging)->allowApi());
    }

    #[Test]
    public function onlyLocalAllowsMutableApi(): void
    {
        self::assertTrue(new ProductionSafetyMode(EnvironmentMode::Local)->allowMutableApi());
        self::assertFalse(new ProductionSafetyMode(EnvironmentMode::Staging)->allowMutableApi());
        self::assertFalse(new ProductionSafetyMode(EnvironmentMode::Production)->allowMutableApi());
    }

    #[Test]
    public function modeReturnsEnvironmentMode(): void
    {
        $mode = new ProductionSafetyMode(EnvironmentMode::Staging);

        self::assertSame(EnvironmentMode::Staging, $mode->mode());
    }
}
