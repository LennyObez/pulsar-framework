<?php

declare(strict_types=1);

namespace Pulsar\ImportExport;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Result of an export operation from a single provider.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ExportResult
{
    /**
     * @param string $providerName Provider that produced this export
     * @param array<string, mixed> $data Exported data keyed by entity type
     * @param string $format Output format used
     * @param string $evidenceHash Integrity hash of the serialized data
     * @param list<string> $entityTypes Entity types included in the export
     * @param list<string> $warnings Non-fatal issues encountered during export
     * @param DateTimeImmutable $createdAt When the export was generated
     */
    public function __construct(
        public string $providerName,
        public array $data,
        public string $format,
        public string $evidenceHash,
        public array $entityTypes,
        public array $warnings = [],
        public DateTimeImmutable $createdAt = new DateTimeImmutable(),
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->providerName,
            'data' => $this->data,
            'format' => $this->format,
            'evidence_hash' => $this->evidenceHash,
            'entity_types' => $this->entityTypes,
            'warnings' => $this->warnings,
            'created_at' => $this->createdAt->format(DateTimeImmutable::ATOM),
        ];
    }
}
