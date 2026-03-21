<?php

declare(strict_types=1);

namespace Pulsar\Tenancy\Guard;

use Fiber;
use LogicException;
use Pulsar\Api\Api;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use stdClass;
use WeakMap;

/**
 * Manages a system-level context that bypasses tenant isolation, isolated
 * per Fiber.
 *
 * Used for cross-tenant administrative operations that must be explicitly
 * entered and exited. The active flag is keyed by `Fiber::getCurrent()`
 * so that one Fiber entering system context does not cause concurrent
 * Fibers' tenant-isolation guards to silently bypass — that would be a
 * cross-Fiber tenant bypass leak (F29.2 follow-up of F13.1 / F25.2 / F24.2).
 * @api
 */
#[Api(since: '1.0.0')]
final class SystemContext
{
    /** @var WeakMap<object, true> Per-Fiber active flag (entry exists iff active). */
    private WeakMap $active;

    private readonly stdClass $rootKey;

    public function __construct(
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {
        /** @var WeakMap<object, true> $map */
        $map = new WeakMap();
        $this->active = $map;
        $this->rootKey = new stdClass();
    }

    /**
     * Whether the current Fiber has an active system context.
     */
    public function isActive(): bool
    {
        return isset($this->active[$this->currentKey()]);
    }

    /**
     * Backwards-compatible accessor for code that read `$ctx->active` as a
     * property. Only the *current Fiber's* state is reported.
     */
    public function __get(string $name): bool
    {
        if ($name === 'active') {
            return $this->isActive();
        }

        throw new LogicException('Unknown property: ' . $name);
    }

    /**
     * Enter system context for a cross-tenant operation.
     *
     * @throws LogicException If already in system context.
     */
    public function enter(string $reason): void
    {
        $key = $this->currentKey();

        if (isset($this->active[$key])) {
            throw new LogicException('System context is already active');
        }

        $this->active[$key] = true;

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
        $key = $this->currentKey();

        if (!isset($this->active[$key])) {
            throw new LogicException('System context is not active');
        }

        unset($this->active[$key]);

        $this->auditLogger?->log(
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: AuditActor::system('tenancy.system_context'),
            action: 'system_context_exited',
        );
    }

    private function currentKey(): object
    {
        return Fiber::getCurrent() ?? $this->rootKey;
    }
}
