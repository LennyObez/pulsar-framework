<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use NoDiscard;
use Pulsar\Api\Api;

use function is_int;
use function is_string;

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
     * @param array<mixed, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawCategory = $data['category'] ?? '';
        $rawDays = $data['retention_days'] ?? 0;
        $rawBasis = $data['legal_basis'] ?? '';

        return new self(
            category: is_string($rawCategory) ? $rawCategory : '',
            retentionDays: is_int($rawDays) ? $rawDays : 0,
            legalBasis: is_string($rawBasis) ? $rawBasis : '',
        );
    }
}
