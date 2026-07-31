<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * A single data retention policy entry.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RetentionPolicy
{
    public function __construct(
        public string $category,
        public int $retentionDays,
        public string $legalBasis = '',
    ) {}

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
        return clone($this, ['retentionDays' => $retentionDays]);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            category: Coerce::string($data['category'] ?? null),
            retentionDays: Coerce::strictInt($data['retention_days'] ?? null, 0),
            legalBasis: Coerce::string($data['legal_basis'] ?? null),
        );
    }
}
