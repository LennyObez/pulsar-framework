<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tenancy\Guard;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Tenancy\Guard\SystemContext;

#[CoversClass(SystemContext::class)]
final class SystemContextTest extends TestCase
{
    #[Test]
    public function test_enter_exit_lifecycle(): void
    {
        $system = new SystemContext();

        self::assertFalse($system->active);

        $system->enter('test operation');
        self::assertTrue($system->active);

        $system->exit();
        self::assertFalse($system->active);
    }

    #[Test]
    public function test_enter_when_active_throws(): void
    {
        $system = new SystemContext();
        $system->enter('first');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('System context is already active');

        $system->enter('second');
    }

    #[Test]
    public function test_exit_when_inactive_throws(): void
    {
        $system = new SystemContext();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('System context is not active');

        $system->exit();
    }

    #[Test]
    public function test_is_active(): void
    {
        $system = new SystemContext();

        self::assertFalse($system->active);

        $system->enter('test');
        self::assertTrue($system->active);

        $system->exit();
        self::assertFalse($system->active);
    }

    #[Test]
    public function test_enter_logs_audit_event(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static fn(mixed $actor): bool => $actor instanceof AuditActor && $actor->id === 'system:tenancy.system_context'),
                'system_context_entered',
                self::anything(),
                self::callback(static fn(array $metadata): bool => $metadata['reason'] === 'migration'),
            );

        $system = new SystemContext($auditLogger);
        $system->enter('migration');
    }

    #[Test]
    public function test_exit_logs_audit_event(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::exactly(2))
            ->method('log');

        $system = new SystemContext($auditLogger);
        $system->enter('migration');
        $system->exit();
    }
}
