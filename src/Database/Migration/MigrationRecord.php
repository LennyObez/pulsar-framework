<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use DateMalformedStringException;
use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * Readonly value object for an applied migration row.
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
        $appliedAt = $data['applied_at'] ?? 'now';
        $dateTime = new DateTimeImmutable(is_string($appliedAt) ? $appliedAt : 'now');

        /** @var string $version */
        $version = $data['version'] ?? '';

        /** @var string $name */
        $name = $data['name'] ?? '';

        /** @var int $batch */
        $batch = $data['batch'] ?? 0;

        return new self(
            version: $version,
            name: $name,
            batch: $batch,
            appliedAt: $dateTime,
        );
    }
}
