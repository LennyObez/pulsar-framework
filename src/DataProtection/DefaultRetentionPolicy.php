<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use DateInterval;
use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Internal;

use function max;

/**
 * Default retention policy that reads configuration from the data_protection
 * config array.
 *
 * This is the framework's built-in implementation; applications may provide
 * custom implementations of RetentionPolicyInterface for domain-specific needs.
 */
#[Internal(reason: 'Default implementation; use RetentionPolicyInterface for type hints')]
final readonly class DefaultRetentionPolicy implements RetentionPolicyInterface
{
    /**
     * @param string          $category      Data category identifier
     * @param non-negative-int $retentionDays Number of days to retain (0 = indefinite)
     * @param string          $legalBasis    Regulatory or legal justification
     */
    public function __construct(
        private string $category,
        private int $retentionDays,
        private string $legalBasis = '',
    ) {}

    /**
     * Build a policy from a raw config array entry.
     *
     * Expected shape:
     * ```php
     * [
     *     'category'       => 'audit_logs',
     *     'retention_days' => 2555,
     *     'legal_basis'    => 'SOX 7-year requirement',
     * ]
     * ```
     *
     * @param array{
     *     category?: string,
     *     retention_days?: int,
     *     legal_basis?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            category: $data['category'] ?? '',
            retentionDays: max(0, $data['retention_days'] ?? 0),
            legalBasis: $data['legal_basis'] ?? '',
        );
    }

    public function category(): string
    {
        return $this->category;
    }

    public function retentionDays(): int
    {
        return $this->retentionDays;
    }

    public function legalBasis(): string
    {
        return $this->legalBasis;
    }

    public function isExpired(DateTimeImmutable $createdAt, DateTimeImmutable $now): bool
    {
        if ($this->retentionDays === 0) {
            return false;
        }

        $expiry = $createdAt->add(new DateInterval("P{$this->retentionDays}D"));

        return $now >= $expiry;
    }
}
