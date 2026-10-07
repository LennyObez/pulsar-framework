<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Support\Coerce;

use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function sprintf;

/**
 * A single data retention policy entry.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RetentionPolicy
{
    /**
     * This constructor is a validation boundary, so the parameter type stays a
     * plain `int`: callers hand over values that came from configuration and may
     * be anything. The guard below is what establishes the invariant — a negative
     * period is meaningless and would be read downstream as 0, i.e. as INDEFINITE
     * retention, the exact opposite of what was asked.
     *
     * @param int $retentionDays Days to retain; 0 means indefinitely.
     */
    public function __construct(
        public string $category,
        public int $retentionDays,
        public string $legalBasis = '',
    ) {
        if ($retentionDays < 0) {
            throw ConfigException::invalidValue(
                sprintf('data_protection.retention[%s].retention_days', $category),
                'a negative retention period is meaningless and would be read as 0 '
                . '(indefinite retention); use 0 explicitly to retain indefinitely',
            );
        }
    }

    /**
     * A copy with a different retention period, in days.
     *
     * Used by compliance enforcement to EXTEND a retention period up to what the
     * active regulatory profile requires. Note that 0 means indefinite retention
     * (see {@see DefaultRetentionPolicy::isExpired()}), i.e. the strictest possible
     * setting — callers must never overwrite a 0 with a finite value, which would
     * start deleting records that were being kept forever.
     */
    #[NoDiscard]
    public function withRetentionDays(int $retentionDays): self
    {
        // clone-with bypasses the constructor, so the non-negative invariant has to
        // be restated here or it could be violated through this door.
        if ($retentionDays < 0) {
            throw ConfigException::invalidValue(
                sprintf('data_protection.retention[%s].retention_days', $this->category),
                'a negative retention period is meaningless and would be read as 0 '
                . '(indefinite retention); use 0 explicitly to retain indefinitely',
            );
        }

        return clone($this, ['retentionDays' => $retentionDays]);
    }

    /**
     * Build one entry of config/data_protection.php's `retention` list.
     *
     * A malformed value is REFUSED rather than silently defaulted, because every
     * silent fallback here means "keep this data forever": `retention_days`
     * defaults to 0 (indefinite) and an unrecognized `category` matches no purger,
     * so the records are never purged. For personal-data categories that is a
     * storage-limitation violation (GDPR Art. 5(1)(e)) produced by a typo, and for
     * audit categories it silently disables the expiry the operator configured.
     *
     * Numeric strings are accepted because `env()` returns every value as a string:
     * `'retention_days' => env('RETENTION_DAYS', 90)` yields "90", which must mean
     * 90 days — not indefinite retention.
     *
     * @param array<string, mixed> $data
     *
     * @throws ConfigException If `category` is missing/blank, or `retention_days`
     *                         is present but not a non-negative number.
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $category = Coerce::string($data['category'] ?? null);

        if ($category === '') {
            throw ConfigException::invalidValue(
                'data_protection.retention[].category',
                'a retention policy must name the category it applies to; a blank or '
                . 'non-string category matches no purger, so the data is never purged',
            );
        }

        /** @var mixed $rawDays */
        $rawDays = $data['retention_days'] ?? null;

        // Absent is legitimate and documented: 0 means indefinite retention.
        if ($rawDays === null) {
            return new self(
                category: $category,
                retentionDays: 0,
                legalBasis: self::legalBasis($data, $category),
            );
        }

        if (!is_int($rawDays) && !(is_string($rawDays) && is_numeric($rawDays)) && !is_float($rawDays)) {
            throw ConfigException::invalidValue(
                sprintf('data_protection.retention[%s].retention_days', $category),
                'expected a number of days (0 = indefinite); a value that cannot be read as a '
                . 'number would silently become 0 and retain the data forever',
            );
        }

        $days = (int) $rawDays;

        if ($days < 0) {
            throw ConfigException::invalidValue(
                sprintf('data_protection.retention[%s].retention_days', $category),
                'a negative retention period is meaningless and would be clamped to 0 '
                . '(indefinite retention); use 0 explicitly to retain indefinitely',
            );
        }

        return new self(
            category: $category,
            retentionDays: $days,
            legalBasis: self::legalBasis($data, $category),
        );
    }

    /**
     * The legal basis is optional — but when present it must be readable, because
     * it is what the purge audit trail cites to justify the retention period.
     * Silently turning a mistyped basis into an empty string would leave a
     * compliance record that documents nothing.
     *
     * @param array<string, mixed> $data
     *
     * @throws ConfigException If `legal_basis` is present but not a string.
     */
    private static function legalBasis(array $data, string $category): string
    {
        /** @var mixed $raw */
        $raw = $data['legal_basis'] ?? null;

        if ($raw === null) {
            return '';
        }

        if (!is_string($raw)) {
            throw ConfigException::invalidValue(
                sprintf('data_protection.retention[%s].legal_basis', $category),
                'expected a string citing the legal basis for this retention period; an '
                . 'unreadable value would be recorded as no justification at all',
            );
        }

        return $raw;
    }
}
