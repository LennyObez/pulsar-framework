<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use DateInterval;
use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Config\Exception\ConfigException;

use function sprintf;

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
     * Like {@see RetentionPolicy::__construct()} this is a validation boundary, so
     * the day count is accepted as a plain `int` and the invariant is established
     * by the guard rather than promised by the type. A negative period would break
     * the DateInterval built in {@see self::isExpired()} and, once clamped, would
     * silently mean indefinite retention.
     *
     * @param string $category      Data category identifier
     * @param int    $retentionDays Number of days to retain (0 = indefinite)
     * @param string $legalBasis    Regulatory or legal justification
     *
     * @throws \Pulsar\Config\Exception\ConfigException If the period is negative.
     */
    /**
     * Validated then assigned: the parameter is a plain `int` because callers pass
     * configuration values, and the property carries the narrowed type that
     * {@see RetentionPolicyInterface::retentionDays()} promises. The guard is what
     * bridges the two, so the invariant is enforced rather than merely declared.
     *
     * @var int<0, max>
     */
    private int $retentionDays;

    public function __construct(
        private string $category,
        int $retentionDays,
        private string $legalBasis = '',
    ) {
        if ($retentionDays < 0) {
            throw ConfigException::invalidValue(
                sprintf('data_protection.retention[%s].retention_days', $category),
                'a negative retention period is meaningless and would be read as 0 '
                . '(indefinite retention); use 0 explicitly to retain indefinitely',
            );
        }

        $this->retentionDays = $retentionDays;
    }

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
     * Parsing is delegated to {@see RetentionPolicy::fromArray()} so both classes
     * read the identical config shape by exactly one set of rules. They previously
     * diverged — this one coerced loosely while the other rejected numeric strings —
     * which is precisely how the same entry could mean 90 days in one place and
     * indefinite retention in the other.
     *
     * @param array<string, mixed> $data
     *
     * @throws \Pulsar\Config\Exception\ConfigException If the entry is malformed.
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $parsed = RetentionPolicy::fromArray($data);

        return new self(
            category: $parsed->category,
            retentionDays: $parsed->retentionDays,
            legalBasis: $parsed->legalBasis,
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
