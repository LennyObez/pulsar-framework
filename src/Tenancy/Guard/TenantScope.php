<?php

declare(strict_types=1);

namespace Pulsar\Tenancy\Guard;

use Pulsar\Api\Api;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Tenancy\Tenant;
use Pulsar\Tenancy\TenantContext;

/**
 * Manages the lifecycle of tenant scope within a request or job.
 */
#[Api(since: '1.0.0')]
final class TenantScope
{
    public private(set) ?TenantId $activeTenantId = null;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Enter a tenant scope, setting the tenant context.
     */
    public function enter(TenantId $tenantId): void
    {
        $tenant = new Tenant(id: $tenantId->toString(), name: $tenantId->toString());
        $this->context->set($tenant);
        $this->activeTenantId = $tenantId;

        $this->auditLogger?->log(
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: AuditActor::system('tenancy.scope'),
            action: 'tenant_scope_entered',
            resource: $tenantId->toString(),
        );
    }

    /**
     * Exit the current tenant scope, clearing the tenant context.
     */
    public function exit(): void
    {
        $previousTenantId = $this->activeTenantId;
        $this->context->clear();
        $this->activeTenantId = null;

        $this->auditLogger?->log(
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: AuditActor::system('tenancy.scope'),
            action: 'tenant_scope_exited',
            resource: $previousTenantId?->toString() ?? '',
        );
    }

    /**
     * Full reset: clears tenant context and nulls active tenant ID.
     *
     * Used between fan-out jobs to ensure no cross-tenant state survives.
     */
    public function reset(): void
    {
        $previousTenantId = $this->activeTenantId;
        $this->context->clear();
        $this->activeTenantId = null;

        if ($previousTenantId !== null) {
            $this->auditLogger?->log(
                event: AuditEvent::SystemEvent,
                outcome: AuditOutcome::Success,
                actor: AuditActor::system('tenancy.scope'),
                action: 'tenant_scope_reset',
                resource: $previousTenantId->toString(),
            );
        }
    }

}
