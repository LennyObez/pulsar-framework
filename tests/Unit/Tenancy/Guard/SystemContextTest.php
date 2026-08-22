<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tenancy\Guard;

use Fiber;
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

        self::assertFalse($system->isActive());

        $system->enter('test operation');
        self::assertTrue($system->isActive());

        $system->exit();
        self::assertFalse($system->isActive());
    }

    #[Test]
    public function test_enter_when_active_throws(): void
    {
        $system = new SystemContext();
        $system->enter('first');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('System context is already active');

        $system->enter('second');
    }

    #[Test]
    public function test_exit_when_inactive_throws(): void
    {
        $system = new SystemContext();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('System context is not active');

        $system->exit();
    }

    #[Test]
    public function test_is_active(): void
    {
        $system = new SystemContext();

        self::assertFalse($system->isActive());

        $system->enter('test');
        self::assertTrue($system->isActive());

        $system->exit();
        self::assertFalse($system->isActive());
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

    #[Test]
    public function fibersDoNotShareActiveFlag(): void
    {
        $system = new SystemContext();
        $system->enter('root-migration');

        $observed = null;
        $fiber = new Fiber(function () use ($system, &$observed): void {
            $observed = ['initial' => $system->isActive()];
            // Fiber starts with no active context regardless of root state.
            $system->enter('fiber-migration');
            $observed['fiber_active'] = $system->isActive();
            $system->exit();
            $observed['fiber_after_exit'] = $system->isActive();
        });

        $fiber->start();

        // Root remains active even though the Fiber entered + exited its own slot.
        self::assertTrue($system->isActive());
        self::assertNotNull($observed, 'Fiber callback should have populated $observed');
        self::assertFalse($observed['initial']);
        self::assertTrue($observed['fiber_active']);
        self::assertFalse($observed['fiber_after_exit']);

        $system->exit();
    }

    #[Test]
    public function fiberCannotExitRootSystemContext(): void
    {
        $system = new SystemContext();
        $system->enter('root');

        $caught = null;
        $fiber = new Fiber(function () use ($system, &$caught): void {
            try {
                $system->exit();
            } catch (LogicException $e) {
                $caught = $e->getMessage();
            }
        });

        $fiber->start();

        self::assertSame('System context is not active', $caught);
        self::assertTrue($system->isActive());

        $system->exit();
    }

    #[Test]
    public function legacyActivePropertyReadsCurrentFiberSlot(): void
    {
        $system = new SystemContext();
        $system->enter('root');

        $fiber = new Fiber(function () use ($system): mixed {
            return $system->isActive();
        });

        $fiber->start();

        // The Fiber observes ITS OWN active flag (false), not the root's (true).
        self::assertFalse($fiber->getReturn());
        self::assertTrue($system->isActive());

        $system->exit();
    }
}
