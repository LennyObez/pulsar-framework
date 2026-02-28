<?php

declare(strict_types=1);

namespace Pulsar\Scheduler\Tenant;

use DateTimeImmutable;
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
 * Tenant-aware scheduler that dispatches due jobs per tenant.
 *
 * For each active tenant, resolves their scheduled jobs, checks for
 * maintenance windows, and dispatches due jobs via the queue with
 * proper tenant scope isolation.
 */
#[Api(since: '1.0.0')]
final readonly class TenantFanOutSchedule
{
    public function __construct(
        private TenantScheduleResolver $resolver,
        private TenantProviderInterface $tenantProvider,
        private QueueManager $queueManager,
        private TenantScope $scope,
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Execute a scheduler tick across all tenants.
     *
     * For each active tenant:
     * 1. Skip if in maintenance window
     * 2. Resolve tenant's scheduled jobs
     * 3. For each due job, dispatch via QueueManager within tenant scope
     */
    #[NoDiscard]
    public function tick(?DateTimeImmutable $now = null): TenantScheduleTickResult
    {
        $now ??= new DateTimeImmutable();
        $tenants = $this->tenantProvider->getActiveTenants();

        $tenantsProcessed = 0;
        $tenantsSkipped = 0;
        $jobsDispatched = 0;
        $jobsFailed = 0;

        foreach ($tenants as $tenantId) {
            if ($this->resolver->isInMaintenanceWindow($tenantId, $now)) {
                ++$tenantsSkipped;

                $this->auditLogger?->log(
                    event: AuditEvent::SystemEvent,
                    outcome: AuditOutcome::Success,
                    actor: AuditActor::system('scheduler.tenant_fanout'),
                    action: 'tenant_schedule_skipped_maintenance',
                    resource: $tenantId->toString(),
                );

                continue;
            }

            $jobs = $this->resolver->resolve($tenantId);
            ++$tenantsProcessed;

            foreach ($jobs as $job) {
                if (!$job->getSchedule()->isDue($now)) {
                    continue;
                }

                try {
                    $this->scope->enter($tenantId);

                    try {
                        $this->queueManager->dispatch(
                            jobClass: $job::class,
                            payload: '',
                            metadata: [
                                'scheduledJobName' => $job->getName(),
                                'tenantId' => $tenantId->toString(),
                            ],
                        );
                    } finally {
                        $this->scope->exit();
                        $this->scope->reset();
                    }

                    ++$jobsDispatched;
                } catch (Throwable $e) {
                    ++$jobsFailed;

                    $this->auditLogger?->log(
                        event: AuditEvent::SystemEvent,
                        outcome: AuditOutcome::Error,
                        actor: AuditActor::system('scheduler.tenant_fanout'),
                        action: 'tenant_schedule_dispatch_failed',
                        resource: $tenantId->toString(),
                        metadata: [
                            'jobName' => $job->getName(),
                            'error' => $e->getMessage(),
                        ],
                    );
                }
            }
        }

        $this->auditLogger?->log(
            event: AuditEvent::SystemEvent,
            outcome: $jobsFailed === 0 ? AuditOutcome::Success : AuditOutcome::Error,
            actor: AuditActor::system('scheduler.tenant_fanout'),
            action: 'tenant_schedule_tick_completed',
            metadata: [
                'totalTenants' => count($tenants),
                'tenantsProcessed' => $tenantsProcessed,
                'tenantsSkipped' => $tenantsSkipped,
                'jobsDispatched' => $jobsDispatched,
                'jobsFailed' => $jobsFailed,
            ],
        );

        return new TenantScheduleTickResult(
            tenantsProcessed: $tenantsProcessed,
            jobsDispatched: $jobsDispatched,
            tenantsSkipped: $tenantsSkipped,
            jobsFailed: $jobsFailed,
        );
    }
}
