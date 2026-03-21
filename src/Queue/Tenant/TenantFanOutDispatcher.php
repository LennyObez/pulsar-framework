<?php

declare(strict_types=1);

namespace Pulsar\Queue\Tenant;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Queue\QueueManager;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Tenancy\Guard\TenantScope;
use Pulsar\Tenancy\TenantProviderInterface;
use Throwable;

use function count;

/**
 * Service-class that fans out a job across all active tenants.
 *
 * For each tenant, temporarily enters the tenant scope so that
 * TenantJobMiddleware captures the tenant ID into the envelope
 * during dispatch. Each tenant's dispatch is an independent failure
 * domain: one failure does not prevent other tenants' jobs from
 * being dispatched.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TenantFanOutDispatcher
{
    public function __construct(
        private TenantProviderInterface $tenantProvider,
        private QueueManager $queueManager,
        private TenantScope $scope,
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Dispatch a job to every active tenant.
     *
     * @param string $innerJobClass Fully-qualified class name of the job to fan out.
     * @param string $innerPayload  Serialized payload for each tenant's job.
     * @param string $queue         Target queue name.
     * @param int    $delay         Delay in seconds before each job becomes available.
     */
    #[NoDiscard]
    public function dispatch(
        string $innerJobClass,
        string $innerPayload,
        string $queue = 'default',
        int $delay = 0,
    ): TenantFanOutResult {
        $tenants = $this->tenantProvider->getActiveTenants();
        $dispatched = 0;
        $failed = 0;
        /** @var list<string> $failedTenantIds */
        $failedTenantIds = [];

        $this->auditLogger?->log(
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: AuditActor::system('queue.tenant_fanout'),
            action: 'tenant_fan_out_started',
            metadata: [
                'innerJobClass' => $innerJobClass,
                'totalTenants' => count($tenants),
            ],
        );

        foreach ($tenants as $tenantId) {
            try {
                $this->scope->enter($tenantId);

                try {
                    $this->queueManager->dispatch(
                        jobClass: $innerJobClass,
                        payload: $innerPayload,
                        queue: $queue,
                        delay: $delay,
                    );
                } finally {
                    $this->scope->exit();
                    $this->scope->reset();
                }

                ++$dispatched;
            } catch (Throwable $e) {
                ++$failed;
                $failedTenantIds[] = $tenantId->toString();

                $this->auditLogger?->log(
                    event: AuditEvent::SystemEvent,
                    outcome: AuditOutcome::Error,
                    actor: AuditActor::system('queue.tenant_fanout'),
                    action: 'tenant_fan_out_dispatch_failed',
                    resource: $tenantId->toString(),
                    metadata: [
                        'innerJobClass' => $innerJobClass,
                        'error' => $e->getMessage(),
                    ],
                );
            }
        }

        $this->auditLogger?->log(
            event: AuditEvent::SystemEvent,
            outcome: $failed === 0 ? AuditOutcome::Success : AuditOutcome::Error,
            actor: AuditActor::system('queue.tenant_fanout'),
            action: 'tenant_fan_out_completed',
            metadata: [
                'innerJobClass' => $innerJobClass,
                'totalTenants' => count($tenants),
                'dispatched' => $dispatched,
                'failed' => $failed,
            ],
        );

        return new TenantFanOutResult(
            dispatched: $dispatched,
            failed: $failed,
            failedTenantIds: $failedTenantIds,
        );
    }
}
