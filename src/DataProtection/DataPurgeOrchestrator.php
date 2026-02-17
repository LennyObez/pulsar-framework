<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function microtime;
use function sprintf;

/**
 * Coordinates multiple DataPurgeInterface implementations.
 *
 * Runs each purge handler against the matching retention policy,
 * logs results via AuditLoggerInterface, and returns aggregated results.
 */
#[Api(since: '1.0.0')]
final readonly class DataPurgeOrchestrator
{
    /**
     * @param array<string, DataPurgeInterface> $purgers Map of category => purge implementation
     * @param array<string, RetentionPolicyInterface> $policies Map of category => retention policy
     */
    public function __construct(
        private array $purgers,
        private array $policies,
        private DataProtectionConfig $config,
        private ?AuditLoggerInterface $auditLogger = null,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Run all configured purge operations.
     *
     * @return list<PurgeResult>
     */
    public function purgeAll(): array
    {
        $results = [];

        foreach ($this->purgers as $category => $purger) {
            $policy = $this->policies[$category] ?? null;

            if ($policy === null) {
                $this->logger?->warning(sprintf(
                    'No retention policy configured for category "%s", skipping purge',
                    $category,
                ));

                continue;
            }

            $results[] = $this->executePurge($category, $purger, $policy);
        }

        return $results;
    }

    /**
     * Run a dry-run of all purge operations without deleting data.
     *
     * @return list<PurgeResult>
     */
    public function dryRun(): array
    {
        $results = [];

        foreach ($this->purgers as $category => $purger) {
            $policy = $this->policies[$category] ?? null;

            if ($policy === null) {
                continue;
            }

            $start = microtime(true);
            $count = $purger->countExpired($policy);
            $durationMs = (microtime(true) - $start) * 1000.0;

            $results[] = new PurgeResult(
                category: $category,
                purgedCount: $count,
                dryRun: true,
                durationMs: $durationMs,
            );
        }

        return $results;
    }

    private function executePurge(
        string $category,
        DataPurgeInterface $purger,
        RetentionPolicyInterface $policy,
    ): PurgeResult {
        $isDryRun = $this->config->purge->dryRun;

        $start = microtime(true);

        if ($isDryRun) {
            $count = $purger->countExpired($policy);
        } else {
            $count = $purger->purge($policy);
        }

        $durationMs = (microtime(true) - $start) * 1000.0;

        $result = new PurgeResult(
            category: $category,
            purgedCount: $count,
            dryRun: $isDryRun,
            durationMs: $durationMs,
        );

        if ($this->config->purge->auditPurgeOperations && $this->auditLogger !== null) {
            $this->auditLogger->log(
                event: AuditEvent::DataModification,
                outcome: AuditOutcome::Success,
                actor: 'system:data_purge',
                action: $isDryRun ? 'data_purge.dry_run' : 'data_purge.execute',
                resource: $category,
                metadata: [
                    'purged_count' => $count,
                    'retention_days' => $policy->retentionDays(),
                    'legal_basis' => $policy->legalBasis(),
                    'dry_run' => $isDryRun,
                    'duration_ms' => $durationMs,
                ],
            );
        }

        $this->logger?->info(sprintf(
            'Data purge %s: category=%s, purged=%d, retention=%dd, duration=%.1fms',
            $isDryRun ? '(dry-run)' : '(executed)',
            $category,
            $count,
            $policy->retentionDays(),
            $durationMs,
        ));

        return $result;
    }
}
