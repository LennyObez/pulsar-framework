<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Extension\Studio\Security\ProductionSafetyMode;

final class ProductionSafetyModeTest extends TestCase
{
    #[Test]
    public function localAllowsEverything(): void
    {
        $mode = new ProductionSafetyMode(EnvironmentMode::Local);

        self::assertTrue($mode->allowDrillDown());
        self::assertTrue($mode->allowStackTraces());
        self::assertTrue($mode->allowSse());
        self::assertTrue($mode->allowRawPayload());
        self::assertTrue($mode->allowApi());
        self::assertTrue($mode->allowMutableApi());
    }

    #[Test]
    public function productionDeniesEverything(): void
    {
        $mode = new ProductionSafetyMode(EnvironmentMode::Production);

        self::assertFalse($mode->allowDrillDown());
        self::assertFalse($mode->allowStackTraces());
        self::assertFalse($mode->allowSse());
        self::assertFalse($mode->allowRawPayload());
        self::assertFalse($mode->allowApi());
        self::assertFalse($mode->allowMutableApi());
    }

    #[Test]
    public function stagingAllowsLimitedAccess(): void
    {
        $mode = new ProductionSafetyMode(EnvironmentMode::Staging);

        self::assertTrue($mode->allowDrillDown());
        self::assertFalse($mode->allowStackTraces());
        self::assertTrue($mode->allowSse());
        self::assertFalse($mode->allowRawPayload());
        self::assertTrue($mode->allowApi());
        self::assertFalse($mode->allowMutableApi());
    }

    #[Test]
    public function modeReturnsEnvironmentMode(): void
    {
        $mode = new ProductionSafetyMode(EnvironmentMode::Staging);

        self::assertSame(EnvironmentMode::Staging, $mode->mode());
    }
}
