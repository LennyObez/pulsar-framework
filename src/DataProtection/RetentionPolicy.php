<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use NoDiscard;
use Pulsar\Api\Api;

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
            retentionDays: $data['retention_days'] ?? 0,
            legalBasis: $data['legal_basis'] ?? '',
        );
    }
}
