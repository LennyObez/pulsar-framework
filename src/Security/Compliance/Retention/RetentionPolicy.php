<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Retention;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Defines retention requirements for a specific regulation.
 *
 * Specifies how long records must be kept before they may be purged.
 * Each policy is versioned to support auditable policy changes.
 */
#[Api(since: '1.0.0')]
final readonly class RetentionPolicy
{
    public function __construct(
        public string $policyId,
        public int $version,
        public string $regulation,
        public int $retentionPeriodDays,
        public DateTimeImmutable $effectiveDate,
        public string $description,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'policy_id' => $this->policyId,
            'version' => $this->version,
            'regulation' => $this->regulation,
            'retention_period_days' => $this->retentionPeriodDays,
            'effective_date' => $this->effectiveDate->format('Y-m-d'),
            'description' => $this->description,
        ];
    }

    /**
     * @param array{policy_id: string, version: int|string, regulation: string, retention_period_days: int|string, effective_date: string, description: string} $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            policyId: $data['policy_id'],
            version: (int) $data['version'],
            regulation: $data['regulation'],
            retentionPeriodDays: (int) $data['retention_period_days'],
            effectiveDate: new DateTimeImmutable($data['effective_date']),
            description: $data['description'],
        );
    }
}
