<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use DateInterval;
use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Internal;

use function is_int;
use function is_numeric;
use function is_string;
use function max;

/**
 * Default retention policy that reads configuration from the data_protection
 * config array.
 *
 * This is the framework's built-in implementation — applications may provide
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawCategory = $data['category'] ?? '';
        $category = is_string($rawCategory) ? $rawCategory : '';

        $rawDays = $data['retention_days'] ?? 0;
        $retentionDays = max(0, is_int($rawDays) ? $rawDays : (int) (is_numeric($rawDays) ? $rawDays : 0));

        $rawBasis = $data['legal_basis'] ?? '';
        $legalBasis = is_string($rawBasis) ? $rawBasis : '';

        return new self(
            category: $category,
            retentionDays: $retentionDays,
            legalBasis: $legalBasis,
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
