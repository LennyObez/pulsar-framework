<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use DateMalformedStringException;
use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Readonly value object for an applied migration row.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MigrationRecord
{
    public function __construct(
        public string $version,
        public string $name,
        public int $batch,
        public DateTimeImmutable $appliedAt,
    ) {}

    /**
     * Build from a raw database row array.
     *
     * @param array<string, mixed> $data
     *
     * @throws DateMalformedStringException
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            version: Coerce::string($data['version'] ?? null),
            name: Coerce::string($data['name'] ?? null),
            batch: Coerce::int($data['batch'] ?? null, 0),
            appliedAt: new DateTimeImmutable(Coerce::string($data['applied_at'] ?? null, 'now')),
        );
    }
}
