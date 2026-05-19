<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use DateMalformedStringException;
use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

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
     * @param array{
     *     version?: string,
     *     name?: string,
     *     batch?: int,
     *     applied_at?: string,
     * } $data
     *
     * @throws DateMalformedStringException
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            version: $data['version'] ?? '',
            name: $data['name'] ?? '',
            batch: $data['batch'] ?? 0,
            appliedAt: new DateTimeImmutable($data['applied_at'] ?? 'now'),
        );
    }
}
