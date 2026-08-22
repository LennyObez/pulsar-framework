<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Reflection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Grpc\Config\ReflectionConfig;
use Pulsar\Extension\Grpc\Reflection\ReflectionGuard;
use Pulsar\Extension\Grpc\Security\GrpcSecurityEvent;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

#[CoversClass(ReflectionGuard::class)]
final class ReflectionGuardTest extends TestCase
{
    #[Test]
    public function isAllowedReturnsFalseWhenReflectionDisabled(): void
    {
        $guard = new ReflectionGuard(new ReflectionConfig(enabled: false));

        self::assertFalse($guard->isAllowed(isProduction: false));
        self::assertFalse($guard->isAllowed(isProduction: true));
    }

    #[Test]
    public function isAllowedReturnsTrueInDevelopmentWhenEnabled(): void
    {
        $guard = new ReflectionGuard(new ReflectionConfig(enabled: true));

        self::assertTrue($guard->isAllowed(isProduction: false));
    }

    #[Test]
    public function isAllowedReturnsFalseInProductionByDefault(): void
    {
        $guard = new ReflectionGuard(new ReflectionConfig(enabled: true, allowInProduction: false));

        self::assertFalse($guard->isAllowed(isProduction: true));
    }

    #[Test]
    public function isAllowedReturnsTrueInProductionWhenExplicitlyAllowed(): void
    {
        $guard = new ReflectionGuard(new ReflectionConfig(enabled: true, allowInProduction: true));

        self::assertTrue($guard->isAllowed(isProduction: true));
    }

    #[Test]
    public function isAllowedAutoEmitsAuditEventInProduction(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::SecurityEvent,
                AuditOutcome::Success,
                'system',
                GrpcSecurityEvent::GrpcReflectionEnabled->value,
                'grpc.reflection',
                ['allow_in_production' => true],
            );

        $config = new ReflectionConfig(enabled: true, allowInProduction: true);
        $guard = new ReflectionGuard($config, $auditLogger);

        $guard->isAllowed(isProduction: true);
    }

    #[Test]
    public function isAllowedDoesNotEmitInDevelopment(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('log');

        $config = new ReflectionConfig(enabled: true, allowInProduction: true);
        $guard = new ReflectionGuard($config, $auditLogger);

        $guard->isAllowed(isProduction: false);
    }

    #[Test]
    public function isAllowedDoesNotEmitWithoutAuditLogger(): void
    {
        $config = new ReflectionConfig(enabled: true, allowInProduction: true);
        $guard = new ReflectionGuard($config);

        // Should not throw; audit logger is optional
        self::assertTrue($guard->isAllowed(isProduction: true));
    }

    #[Test]
    public function onEnabledEmitsSecurityEventViaAuditLogger(): void
    {
        $config = new ReflectionConfig(enabled: true, allowInProduction: true);
        $guard = new ReflectionGuard($config);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::SecurityEvent,
                AuditOutcome::Success,
                'system',
                GrpcSecurityEvent::GrpcReflectionEnabled->value,
                'grpc.reflection',
                ['allow_in_production' => true],
            );

        $guard->onEnabled($auditLogger);
    }

    #[Test]
    public function onEnabledIncludesAllowInProductionFalseInMetadata(): void
    {
        $config = new ReflectionConfig(enabled: true, allowInProduction: false);
        $guard = new ReflectionGuard($config);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::SecurityEvent,
                AuditOutcome::Success,
                'system',
                GrpcSecurityEvent::GrpcReflectionEnabled->value,
                'grpc.reflection',
                ['allow_in_production' => false],
            );

        $guard->onEnabled($auditLogger);
    }
}
