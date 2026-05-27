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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            category: Coerce::string($data['category'] ?? null),
            retentionDays: Coerce::int($data['retention_days'] ?? null, 0),
            legalBasis: Coerce::string($data['legal_basis'] ?? null),
        );
    }
}
