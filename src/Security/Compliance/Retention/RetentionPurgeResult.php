<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Retention;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of a retention purge operation (or dry-run).
 *
 * Contains the policy applied, affected date range, record count,
 * operator identity, and whether this was a dry-run.
 */
#[Api(since: '1.0.0')]
final readonly class RetentionPurgeResult
{
    public function __construct(
        public string $policyId,
        public int $policyVersion,
        public DateTimeImmutable $affectedStartDate,
        public DateTimeImmutable $affectedEndDate,
        public int $recordCount,
        public string $operatorIdentity,
        public DateTimeImmutable $executedAt,
        public bool $dryRun,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'policy_id' => $this->policyId,
            'policy_version' => $this->policyVersion,
            'affected_start_date' => $this->affectedStartDate->format('Y-m-d\TH:i:s.uP'),
            'affected_end_date' => $this->affectedEndDate->format('Y-m-d\TH:i:s.uP'),
            'record_count' => $this->recordCount,
            'operator_identity' => $this->operatorIdentity,
            'executed_at' => $this->executedAt->format('Y-m-d\TH:i:s.uP'),
            'dry_run' => $this->dryRun,
        ];
    }
}
