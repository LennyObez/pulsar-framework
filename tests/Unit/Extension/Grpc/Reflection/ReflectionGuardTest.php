<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Reflection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Grpc\Config\ReflectionConfig;
use Pulsar\Extension\Grpc\Reflection\ReflectionGuard;

#[CoversClass(ReflectionGuard::class)]
final class ReflectionGuardTest extends TestCase
{
    #[Test]
    public function disabledReflectionDeniesInDev(): void
    {
        $config = new ReflectionConfig(enabled: false);
        $guard = new ReflectionGuard($config);

        self::assertFalse($guard->isAllowed(isProduction: false));
    }

    #[Test]
    public function disabledReflectionDeniesInProduction(): void
    {
        $config = new ReflectionConfig(enabled: false);
        $guard = new ReflectionGuard($config);

        self::assertFalse($guard->isAllowed(isProduction: true));
    }

    #[Test]
    public function enabledReflectionAllowsInDev(): void
    {
        $config = new ReflectionConfig(enabled: true);
        $guard = new ReflectionGuard($config);

        self::assertTrue($guard->isAllowed(isProduction: false));
    }

    #[Test]
    public function enabledReflectionDeniedInProductionByDefault(): void
    {
        $config = new ReflectionConfig(enabled: true, allowInProduction: false);
        $guard = new ReflectionGuard($config);

        self::assertFalse($guard->isAllowed(isProduction: true));
    }

    #[Test]
    public function forceEnabledInProductionAllowsAndEmitsAudit(): void
    {
        $config = new ReflectionConfig(enabled: true, allowInProduction: true);

        /** @var AuditLoggerInterface&MockObject $audit */
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::once())->method('log');

        $guard = new ReflectionGuard($config, $audit);

        self::assertTrue($guard->isAllowed(isProduction: true));
    }

    #[Test]
    public function forceEnabledInProductionWithoutAuditLogger(): void
    {
        $config = new ReflectionConfig(enabled: true, allowInProduction: true);
        $guard = new ReflectionGuard($config);

        self::assertTrue($guard->isAllowed(isProduction: true));
    }

    #[Test]
    public function onEnabledEmitsAuditEvent(): void
    {
        $config = new ReflectionConfig(enabled: true, allowInProduction: true);

        /** @var AuditLoggerInterface&MockObject $audit */
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::once())->method('log');

        $guard = new ReflectionGuard($config);
        $guard->onEnabled($audit);
    }
}
