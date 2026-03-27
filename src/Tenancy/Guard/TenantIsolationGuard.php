<?php

declare(strict_types=1);

namespace Pulsar\Tenancy\Guard;

use Pulsar\Api\Api;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Tenancy\Exception\TenantContextMismatchException;
use Pulsar\Tenancy\Exception\TenantContextMissingException;
use Pulsar\Tenancy\TenantContext;

/**
 * Enforces that tenant context is present and correct before operations proceed.
 *
 * When a {@see SystemContext} is provided and active, assertions are bypassed
 * with audit logging: this allows system-level operations (migrations, global
 * maintenance) to operate without tenant scope.
 */
#[Api(since: '1.0.0')]
final readonly class TenantIsolationGuard
{
    public function __construct(
        private TenantContext $context,
        private ?AuditLoggerInterface $auditLogger = null,
        private ?SystemContext $systemContext = null,
    ) {}

    /**
     * Assert that a tenant context has been resolved.
     *
     * Bypassed when system context is active (with audit logging).
     *
     * @throws TenantContextMissingException If no tenant context is active.
     */
    public function assertContext(): void
    {
        if ($this->isSystemContextActive()) {
            return;
        }

        if (! $this->context->isResolved()) {
            throw TenantContextMissingException::forOperation('tenant context assertion');
        }
    }

    /**
     * Assert that the current tenant context matches the expected tenant.
     *
     * Bypassed when system context is active (with audit logging).
     *
     * @throws TenantContextMissingException  If no tenant context is active.
     * @throws TenantContextMismatchException If the active tenant does not match the expected one.
     */
    public function assertContextMatches(TenantId $expected): void
    {
        if ($this->isSystemContextActive()) {
            return;
        }

        if (! $this->context->isResolved()) {
            throw TenantContextMissingException::forOperation('tenant context match assertion');
        }

        $actualId = $this->context->get()->id;

        if ($actualId !== $expected->toString()) {
            $this->auditLogger?->log(
                event: AuditEvent::SecurityEvent,
                outcome: AuditOutcome::Denied,
                actor: AuditActor::system('tenancy.isolation'),
                action: 'tenant_context_mismatch',
                resource: $expected->toString(),
                metadata: [
                    'expected' => $expected->toString(),
                    'actual' => $actualId,
                ],
            );

            throw TenantContextMismatchException::detected($expected->toString(), $actualId);
        }
    }

    /**
     * Check whether system context is active, logging the bypass.
     */
    private function isSystemContextActive(): bool
    {
        if ($this->systemContext === null || ! $this->systemContext->active) {
            return false;
        }

        $this->auditLogger?->log(
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: AuditActor::system('tenancy.isolation'),
            action: 'tenant_guard_bypassed_system_context',
        );

        return true;
    }
}
