<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Studio\Security\ProductionSafetyMode;

#[CoversClass(ProductionSafetyMode::class)]
final class ProductionSafetyModeTest extends TestCase
{
    #[Test]
    public function localModeAllowsDrillDown(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Local);

        self::assertTrue($safety->allowDrillDown());
    }

    #[Test]
    public function stagingModeAllowsDrillDown(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Staging);

        self::assertTrue($safety->allowDrillDown());
    }

    #[Test]
    public function productionModeDeniesDrillDown(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Production);

        self::assertFalse($safety->allowDrillDown());
    }

    #[Test]
    public function localModeAllowsStackTraces(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Local);

        self::assertTrue($safety->allowStackTraces());
    }

    #[Test]
    public function stagingModeDeniesStackTraces(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Staging);

        self::assertFalse($safety->allowStackTraces());
    }

    #[Test]
    public function productionModeDeniesStackTraces(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Production);

        self::assertFalse($safety->allowStackTraces());
    }

    #[Test]
    public function localModeAllowsSse(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Local);

        self::assertTrue($safety->allowSse());
    }

    #[Test]
    public function stagingModeAllowsSse(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Staging);

        self::assertTrue($safety->allowSse());
    }

    #[Test]
    public function productionModeDeniesSse(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Production);

        self::assertFalse($safety->allowSse());
    }

    #[Test]
    public function localModeAllowsRawPayload(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Local);

        self::assertTrue($safety->allowRawPayload());
    }

    #[Test]
    public function stagingModeDeniesRawPayload(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Staging);

        self::assertFalse($safety->allowRawPayload());
    }

    #[Test]
    public function productionModeDeniesRawPayload(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Production);

        self::assertFalse($safety->allowRawPayload());
    }

    #[Test]
    public function localModeAllowsApi(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Local);

        self::assertTrue($safety->allowApi());
    }

    #[Test]
    public function stagingModeAllowsApi(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Staging);

        self::assertTrue($safety->allowApi());
    }

    #[Test]
    public function productionModeDeniesApi(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Production);

        self::assertFalse($safety->allowApi());
    }

    #[Test]
    public function localModeAllowsMutableApi(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Local);

        self::assertTrue($safety->allowMutableApi());
    }

    #[Test]
    public function stagingModeDeniesMutableApi(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Staging);

        self::assertFalse($safety->allowMutableApi());
    }

    #[Test]
    public function productionModeDeniesMutableApi(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Production);

        self::assertFalse($safety->allowMutableApi());
    }

    #[Test]
    public function modeReturnsConfiguredEnvironmentMode(): void
    {
        $localSafety = new ProductionSafetyMode(EnvironmentMode::Local);
        $stagingSafety = new ProductionSafetyMode(EnvironmentMode::Staging);
        $productionSafety = new ProductionSafetyMode(EnvironmentMode::Production);

        self::assertSame(EnvironmentMode::Local, $localSafety->mode());
        self::assertSame(EnvironmentMode::Staging, $stagingSafety->mode());
        self::assertSame(EnvironmentMode::Production, $productionSafety->mode());
    }

    #[Test]
    public function localModeHasFullAccess(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Local);

        self::assertTrue($safety->allowDrillDown());
        self::assertTrue($safety->allowStackTraces());
        self::assertTrue($safety->allowSse());
        self::assertTrue($safety->allowRawPayload());
        self::assertTrue($safety->allowApi());
        self::assertTrue($safety->allowMutableApi());
    }

    #[Test]
    public function stagingModeHasLimitedAccess(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Staging);

        self::assertTrue($safety->allowDrillDown());
        self::assertFalse($safety->allowStackTraces());
        self::assertTrue($safety->allowSse());
        self::assertFalse($safety->allowRawPayload());
        self::assertTrue($safety->allowApi());
        self::assertFalse($safety->allowMutableApi());
    }

    #[Test]
    public function productionModeHasMinimalAccess(): void
    {
        $safety = new ProductionSafetyMode(EnvironmentMode::Production);

        self::assertFalse($safety->allowDrillDown());
        self::assertFalse($safety->allowStackTraces());
        self::assertFalse($safety->allowSse());
        self::assertFalse($safety->allowRawPayload());
        self::assertFalse($safety->allowApi());
        self::assertFalse($safety->allowMutableApi());
    }
}
