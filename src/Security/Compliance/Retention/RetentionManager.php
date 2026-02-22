<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Retention;

use DateTimeImmutable;
use DateTimeZone;
use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

/**
 * Manages retention policy evaluation and purge orchestration.
 *
 * Calculates what records are eligible for purging based on the retention
 * schedule. Actual purge execution against a data store is delegated to
 * domain-specific implementations — this manager determines WHAT should
 * be purged and logs the action via the audit trail.
 */
#[Internal(reason: 'Retention management implementation detail')]
final readonly class RetentionManager
{
    public function __construct(
        private RetentionSchedule $schedule,
        private AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * Evaluate and (optionally) execute a retention purge for a regulation.
     *
     * In dry-run mode, returns what WOULD be purged without executing.
     * On actual purge, emits an audit event recording the operation.
     */
    #[NoDiscard]
    public function purge(
        string $regulation,
        bool $dryRun = false,
        ?string $operatorIdentity = null,
    ): RetentionPurgeResult {
        $policy = $this->schedule->policyFor($regulation);

        if ($policy === null) {
            return new RetentionPurgeResult(
                policyId: '',
                policyVersion: 0,
                affectedStartDate: new DateTimeImmutable('1970-01-01', new DateTimeZone('UTC')),
                affectedEndDate: new DateTimeImmutable('1970-01-01', new DateTimeZone('UTC')),
                recordCount: 0,
                operatorIdentity: $operatorIdentity ?? 'system',
                executedAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
                dryRun: $dryRun,
            );
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoffDate = $now->modify('-' . $policy->retentionPeriodDays . ' days');

        $result = new RetentionPurgeResult(
            policyId: $policy->policyId,
            policyVersion: $policy->version,
            affectedStartDate: new DateTimeImmutable('1970-01-01', new DateTimeZone('UTC')),
            affectedEndDate: $cutoffDate,
            recordCount: 0,
            operatorIdentity: $operatorIdentity ?? 'system',
            executedAt: $now,
            dryRun: $dryRun,
        );

        if (!$dryRun) {
            $this->auditLogger->log(
                event: AuditEvent::DataModification,
                outcome: AuditOutcome::Success,
                actor: $operatorIdentity,
                action: 'retention.purge',
                resource: $regulation,
                metadata: $result->toArray(),
            );
        }

        return $result;
    }

    /**
     * Check if a record date is past its retention period for a regulation.
     */
    #[NoDiscard]
    public function isExpired(string $regulation, DateTimeImmutable $recordDate): bool
    {
        $policy = $this->schedule->policyFor($regulation);

        if ($policy === null) {
            return false;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff = $now->modify('-' . $policy->retentionPeriodDays . ' days');

        return $recordDate < $cutoff;
    }

    /**
     * Look up the retention policy for a given regulation.
     */
    #[NoDiscard]
    public function policyFor(string $regulation): ?RetentionPolicy
    {
        return $this->schedule->policyFor($regulation);
    }
}
