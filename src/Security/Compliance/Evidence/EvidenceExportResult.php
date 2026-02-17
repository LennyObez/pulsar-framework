<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Evidence;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of an evidence export operation.
 *
 * Contains the archive identifier, record count, integrity hash manifest,
 * export timestamp, and whether the export was encrypted.
 */
#[Api(since: '1.0.0')]
readonly class EvidenceExportResult
{
    public function __construct(
        public string $archiveId,
        public int $recordCount,
        public string $hashManifest,
        public DateTimeImmutable $exportedAt,
        public bool $encrypted,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'archive_id' => $this->archiveId,
            'record_count' => $this->recordCount,
            'hash_manifest' => $this->hashManifest,
            'exported_at' => $this->exportedAt->format('Y-m-d\TH:i:s.uP'),
            'encrypted' => $this->encrypted,
        ];
    }
}
