<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Retention;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

use function array_values;
use function str_replace;
use function strtolower;

/**
 * Collection of retention policies organized by regulation.
 *
 * Provides lookup by regulation name and ships with sensible defaults
 * for common regulatory frameworks. Regulation identifiers are normalized
 * (lowercased, hyphens replaced with underscores) so that both 'SOX'
 * and 'sox', or 'PCI-DSS' and 'pci_dss', resolve to the same policy.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RetentionSchedule
{
    /**
     * @var array<string, RetentionPolicy>
     */
    private array $policiesByRegulation;

    /**
     * @param list<RetentionPolicy> $policies
     */
    public function __construct(array $policies)
    {
        $indexed = [];

        foreach ($policies as $policy) {
            $indexed[self::normalizeRegulation($policy->regulation)] = $policy;
        }

        $this->policiesByRegulation = $indexed;
    }

    /**
     * Look up the retention policy for a given regulation.
     *
     * Regulation identifiers are normalized for case-insensitive,
     * format-insensitive lookup (e.g. 'PCI-DSS' and 'pci_dss' both work).
     */
    #[NoDiscard]
    public function policyFor(string $regulation): ?RetentionPolicy
    {
        return $this->policiesByRegulation[self::normalizeRegulation($regulation)] ?? null;
    }

    /**
     * Return all policies in this schedule.
     *
     * @return list<RetentionPolicy>
     */
    #[NoDiscard]
    public function all(): array
    {
        return array_values($this->policiesByRegulation);
    }

    /**
     * Create a schedule with standard regulatory defaults.
     *
     * - SOX: 2555 days (7 years)
     * - HIPAA: 2190 days (6 years)
     * - PCI-DSS: 365 days (1 year)
     * - GDPR: 365 days (1 year minimum)
     * - DORA: 1825 days (5 years)
     * - AML: 1825 days (5 years)
     */
    #[NoDiscard]
    public static function default(): self
    {
        $effectiveDate = new DateTimeImmutable('2024-01-01');

        return new self([
            new RetentionPolicy(
                policyId: 'sox-default',
                version: 1,
                regulation: 'SOX',
                retentionPeriodDays: 2555,
                effectiveDate: $effectiveDate,
                description: 'SOX Section 802: 7-year retention for audit work papers and financial records',
            ),
            new RetentionPolicy(
                policyId: 'hipaa-default',
                version: 1,
                regulation: 'HIPAA',
                retentionPeriodDays: 2190,
                effectiveDate: $effectiveDate,
                description: 'HIPAA 45 CFR 164.530(j): 6-year retention for policies and compliance documentation',
            ),
            new RetentionPolicy(
                policyId: 'pci-dss-default',
                version: 1,
                regulation: 'PCI-DSS',
                retentionPeriodDays: 365,
                effectiveDate: $effectiveDate,
                description: 'PCI DSS Requirement 10.7: 1-year retention for audit trail history',
            ),
            new RetentionPolicy(
                policyId: 'gdpr-default',
                version: 1,
                regulation: 'GDPR',
                retentionPeriodDays: 365,
                effectiveDate: $effectiveDate,
                description: 'GDPR Article 5(1)(e): minimum retention period with storage limitation principle',
            ),
            new RetentionPolicy(
                policyId: 'dora-default',
                version: 1,
                regulation: 'DORA',
                retentionPeriodDays: 1825,
                effectiveDate: $effectiveDate,
                description: 'DORA Article 12: 5-year retention for ICT-related incident records',
            ),
            new RetentionPolicy(
                policyId: 'aml-default',
                version: 1,
                regulation: 'AML',
                retentionPeriodDays: 1825,
                effectiveDate: $effectiveDate,
                description: 'AML/KYC 4AMLD Article 40: 5-year retention for transaction and identity records',
            ),
        ]);
    }

    /**
     * Normalize a regulation identifier for consistent lookup.
     *
     * Lowercases and replaces hyphens with underscores so that
     * 'PCI-DSS', 'pci-dss', and 'pci_dss' all resolve identically.
     */
    private static function normalizeRegulation(string $regulation): string
    {
        return strtolower(str_replace('-', '_', $regulation));
    }
}
