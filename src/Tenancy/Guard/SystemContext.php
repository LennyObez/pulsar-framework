<?php

declare(strict_types=1);

namespace Pulsar\Tenancy\Guard;

use LogicException;
use Pulsar\Api\Api;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

/**
 * Manages a system-level context that bypasses tenant isolation.
 *
 * Used for cross-tenant administrative operations that must be explicitly entered and exited.
 */
#[Api(since: '1.0.0')]
final class SystemContext
{
    public private(set) bool $active = false;

    public function __construct(
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Enter system context for a cross-tenant operation.
     *
     * @throws LogicException If already in system context.
     */
    public function enter(string $reason): void
    {
        if ($this->active) {
            throw new LogicException('System context is already active');
        }

        $this->active = true;

        $this->auditLogger?->log(
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: AuditActor::system('tenancy.system_context'),
            action: 'system_context_entered',
            metadata: ['reason' => $reason],
        );
    }

    /**
     * Exit system context.
     *
     * @throws LogicException If not in system context.
     */
    public function exit(): void
    {
        if (! $this->active) {
            throw new LogicException('System context is not active');
        }

        $this->active = false;

        $this->auditLogger?->log(
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: AuditActor::system('tenancy.system_context'),
            action: 'system_context_exited',
        );
    }

}
