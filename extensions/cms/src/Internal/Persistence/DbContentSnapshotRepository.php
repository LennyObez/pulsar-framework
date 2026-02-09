<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\EventStore\ContentSnapshot;
use Pulsar\Extension\Cms\EventStore\ContentSnapshotServiceInterface;
use Pulsar\Extension\Cms\Support\UuidGenerator;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[Internal(reason: 'Raw-DB repository — use ContentSnapshotServiceInterface for public API')]
final readonly class DbContentSnapshotRepository implements ContentSnapshotServiceInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM cms_content_snapshots WHERE id = :id
        SQL;

    private const string SQL_GET_SNAPSHOTS = <<<'SQL'
        SELECT * FROM cms_content_snapshots
        WHERE content_id = :content_id
        ORDER BY snapshot_number ASC
        SQL;

    private const string SQL_NEXT_SNAPSHOT_NUMBER = <<<'SQL'
        SELECT COALESCE(MAX(snapshot_number), 0) + 1 AS next_num
        FROM cms_content_snapshots
        WHERE content_id = :content_id
        SQL;

    private const string SQL_INSERT = <<<'SQL'
        INSERT INTO cms_content_snapshots (
            id, content_id, snapshot_number, translations_json, blocks_json,
            taxonomy_term_ids, evidence_hash, reason, created_by, created_at
        ) VALUES (
            :id, :content_id, :snapshot_number, :translations_json, :blocks_json,
            :taxonomy_term_ids, :evidence_hash, :reason, :created_by, :created_at
        )
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function capture(string $contentId, string $reason, string $createdBy): ContentSnapshot
    {
        // Full capture orchestrated by domain service layer (reads translations, blocks, terms).
        // This persistence layer provides saveSnapshot() for the service to use.
        $nextNum = $this->getNextSnapshotNumber($contentId);

        $snapshot = new ContentSnapshot(
            id: UuidGenerator::v7(),
            contentId: $contentId,
            snapshotNumber: $nextNum,
            translationsJson: [],
            blocksJson: [],
            taxonomyTermIds: [],
            evidenceHash: hash('blake2b', ''),
            reason: $reason,
            createdBy: $createdBy,
            createdAt: new DateTimeImmutable(),
        );

        $this->saveSnapshot($snapshot);

        return $snapshot;
    }

    public function restore(string $snapshotId, string $restoredBy): void
    {
        // Restore orchestrated by domain service layer (creates revisions per locale).
    }

    public function getSnapshots(string $contentId): array
    {
        $result = $this->connection->query(self::SQL_GET_SNAPSHOTS, [
            'content_id' => $contentId,
        ]);

        return $result->map(self::hydrate(...));
    }

    public function findById(string $id): ?ContentSnapshot
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function saveSnapshot(ContentSnapshot $snapshot): void
    {
        $this->connection->execute(self::SQL_INSERT, [
            'id' => $snapshot->id,
            'content_id' => $snapshot->contentId,
            'snapshot_number' => $snapshot->snapshotNumber,
            'translations_json' => json_encode($snapshot->translationsJson, JSON_THROW_ON_ERROR),
            'blocks_json' => json_encode($snapshot->blocksJson, JSON_THROW_ON_ERROR),
            'taxonomy_term_ids' => json_encode($snapshot->taxonomyTermIds, JSON_THROW_ON_ERROR),
            'evidence_hash' => $snapshot->evidenceHash,
            'reason' => $snapshot->reason,
            'created_by' => $snapshot->createdBy,
            'created_at' => $snapshot->createdAt->format('c'),
        ]);
    }

    private function getNextSnapshotNumber(string $contentId): int
    {
        $result = $this->connection->query(self::SQL_NEXT_SNAPSHOT_NUMBER, [
            'content_id' => $contentId,
        ]);

        return $result->first()?->getInt('next_num') ?? 1;
    }

    private static function hydrate(Row $row): ContentSnapshot
    {
        /** @var array<string, mixed> $translationsJson */
        $translationsJson = json_decode($row->getString('translations_json'), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $blocksJson */
        $blocksJson = json_decode($row->getString('blocks_json'), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<string> $taxonomyTermIds */
        $taxonomyTermIds = json_decode($row->getString('taxonomy_term_ids'), true, 512, JSON_THROW_ON_ERROR);

        return new ContentSnapshot(
            id: $row->getString('id'),
            contentId: $row->getString('content_id'),
            snapshotNumber: $row->getInt('snapshot_number'),
            translationsJson: $translationsJson,
            blocksJson: $blocksJson,
            taxonomyTermIds: $taxonomyTermIds,
            evidenceHash: $row->getString('evidence_hash'),
            reason: $row->getString('reason'),
            createdBy: $row->getString('created_by'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }
}
