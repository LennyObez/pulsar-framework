<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\EventStore;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRevision;
use Pulsar\Extension\Cms\Content\ContentRevisionRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Support\UuidGenerator;

use function is_array;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Service for atomic content snapshots across all locales.
 *
 * Captures the complete state of a content item (all translations, blocks,
 * and taxonomy term bindings) as a single immutable record with an evidence hash.
 * Used in governance-grade environments to prove exact state at publish time.
 *
 * @psalm-api Resolved by content service for governance-grade snapshots;
 *            not new'd by name.
 */
#[Internal]
final readonly class ContentSnapshotService implements ContentSnapshotServiceInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private CmsConfig $config,
        private ContentTranslationRepositoryInterface $translationRepository,
        private ContentBlockRepositoryInterface $blockRepository,
        private ContentRevisionRepositoryInterface $revisionRepository,
    ) {}

    /**
     * Whether snapshots are active (CmsConfig.atomicSnapshots enabled).
     */
    public function isEnabled(): bool
    {
        return $this->config->atomicSnapshots;
    }

    public function capture(string $contentId, string $reason, string $createdBy): ContentSnapshot
    {
        return $this->db->transaction(function (ConnectionInterface $db) use ($contentId, $reason, $createdBy): ContentSnapshot {
            // Get the next snapshot number
            $result = $db->query(
                'SELECT COALESCE(MAX(snapshot_number), 0) AS max_num FROM cms_content_snapshots WHERE content_id = :content_id',
                ['content_id' => $contentId],
            );

            $nextNumber = ($result->firstOrFail()->getInt('max_num')) + 1;

            // Serialize all translations
            $translations = $this->translationRepository->findByContentId($contentId);

            /** @var list<array<string, mixed>> $translationsJson */
            $translationsJson = array_map(
                static fn(ContentTranslation $t): array => [
                    'id' => $t->id,
                    'locale' => $t->locale,
                    'title' => $t->title,
                    'slug_segment' => $t->slugSegment,
                    'path' => $t->path,
                    'body' => $t->body,
                    'excerpt' => $t->excerpt,
                    'meta_title' => $t->metaTitle,
                    'meta_description' => $t->metaDescription,
                    'og_image_id' => $t->ogImageId,
                    'robots' => $t->robots,
                    'structured_data_overrides' => $t->structuredDataOverrides,
                    'reading_time_minutes' => $t->readingTimeMinutes,
                ],
                $translations,
            );

            // Serialize all blocks per locale
            /** @var list<array<string, mixed>> $blocksJson */
            $blocksJson = [];

            foreach ($translations as $translation) {
                $blocks = $this->blockRepository->findByContentAndLocale($contentId, $translation->locale);

                foreach ($blocks as $block) {
                    $blocksJson[] = [
                        'id' => $block->id,
                        'locale' => $block->locale,
                        'block_type' => $block->blockType,
                        'sort_order' => $block->sortOrder,
                        'data' => $block->data,
                    ];
                }
            }

            // Get taxonomy term bindings
            $termResult = $db->query(
                'SELECT term_id FROM cms_content_taxonomy_terms WHERE content_id = :content_id ORDER BY term_id',
                ['content_id' => $contentId],
            );

            $taxonomyTermIds = [];

            foreach ($termResult->rows as $row) {
                $taxonomyTermIds[] = $row->getString('term_id');
            }

            // Compute evidence hash over entire payload
            $evidenceHash = self::computeSnapshotHash($translationsJson, $blocksJson, $taxonomyTermIds);

            $now = new DateTimeImmutable();
            $id = UuidGenerator::v7();

            $snapshot = new ContentSnapshot(
                id: $id,
                contentId: $contentId,
                snapshotNumber: $nextNumber,
                translationsJson: $translationsJson,
                blocksJson: $blocksJson,
                taxonomyTermIds: $taxonomyTermIds,
                evidenceHash: $evidenceHash,
                reason: $reason,
                createdBy: $createdBy,
                createdAt: $now,
            );

            $db->execute(
                <<<'SQL'
                    INSERT INTO cms_content_snapshots (id, content_id, snapshot_number, translations_json, blocks_json, taxonomy_term_ids, evidence_hash, reason, created_by, created_at)
                    VALUES (:id, :content_id, :snapshot_number, :translations_json, :blocks_json, :taxonomy_term_ids, :evidence_hash, :reason, :created_by, :created_at)
                    SQL,
                [
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
                ],
            );

            return $snapshot;
        });
    }

    public function restore(string $snapshotId, string $restoredBy): void
    {
        $row = $this->db->query(
            'SELECT id, content_id, snapshot_number, translations_json, blocks_json, taxonomy_term_ids, evidence_hash, reason, created_by, created_at FROM cms_content_snapshots WHERE id = :id',
            ['id' => $snapshotId],
        )->first();

        if ($row === null) {
            throw CmsException::contentNotFound($snapshotId);
        }

        /** @var list<array{
         *     id?: string,
         *     locale?: string,
         *     title?: string,
         *     slug_segment?: string,
         *     path?: string,
         *     body?: string,
         *     excerpt?: string|null,
         *     meta_title?: string|null,
         *     meta_description?: string|null,
         *     og_image_id?: string|null,
         *     robots?: string|null,
         *     structured_data_overrides?: array<string, mixed>|null,
         *     reading_time_minutes?: int|null,
         * }> $translationsData
         */
        $translationsData = json_decode($row->getString('translations_json'), true, 512, JSON_THROW_ON_ERROR);

        $this->db->transaction(function (ConnectionInterface $db) use ($translationsData, $row, $restoredBy): void {
            $contentId = $row->getString('content_id');
            $snapshotNumber = $row->getInt('snapshot_number');

            foreach ($translationsData as $data) {
                $locale = $data['locale'] ?? '';

                $translation = new ContentTranslation(
                    id: $data['id'] ?? '',
                    contentId: $contentId,
                    locale: $locale,
                    title: $data['title'] ?? '',
                    slugSegment: $data['slug_segment'] ?? '',
                    path: $data['path'] ?? '',
                    body: $data['body'] ?? '',
                    excerpt: $data['excerpt'] ?? null,
                    metaTitle: $data['meta_title'] ?? null,
                    metaDescription: $data['meta_description'] ?? null,
                    ogImageId: $data['og_image_id'] ?? null,
                    robots: $data['robots'] ?? null,
                    structuredDataOverrides: $data['structured_data_overrides'] ?? null,
                    readingTimeMinutes: $data['reading_time_minutes'] ?? null,
                    bodyPlaintext: '',
                    headingsText: '',
                    customFieldsText: '',
                    taxonomyTermsText: '',
                );

                $this->translationRepository->save($translation);

                // Create a revision recording the restoration
                $revisionNumber = $this->revisionRepository->getLatestRevisionNumber($contentId, $locale) + 1;

                $revision = ContentRevision::fromTranslation(
                    id: UuidGenerator::v7(),
                    translation: $translation,
                    revisionNumber: $revisionNumber,
                    authorId: $restoredBy,
                    reason: sprintf('Restored from snapshot #%d', $snapshotNumber),
                );

                $this->revisionRepository->save($revision);
            }
        });
    }

    public function getSnapshots(string $contentId): array
    {
        $result = $this->db->query(
            'SELECT id, content_id, snapshot_number, translations_json, blocks_json, taxonomy_term_ids, evidence_hash, reason, created_by, created_at FROM cms_content_snapshots WHERE content_id = :content_id ORDER BY snapshot_number DESC',
            ['content_id' => $contentId],
        );

        $snapshots = [];

        foreach ($result->rows as $row) {
            /** @var list<array<string, mixed>> $translationsDecoded */
            $translationsDecoded = json_decode($row->getString('translations_json'), true, 512, JSON_THROW_ON_ERROR);
            /** @var list<array<string, mixed>> $blocksDecoded */
            $blocksDecoded = json_decode($row->getString('blocks_json'), true, 512, JSON_THROW_ON_ERROR);
            /** @var list<string> $termIdsDecoded */
            $termIdsDecoded = json_decode($row->getString('taxonomy_term_ids'), true, 512, JSON_THROW_ON_ERROR);

            $snapshots[] = new ContentSnapshot(
                id: $row->getString('id'),
                contentId: $row->getString('content_id'),
                snapshotNumber: $row->getInt('snapshot_number'),
                translationsJson: $translationsDecoded,
                blocksJson: $blocksDecoded,
                taxonomyTermIds: $termIdsDecoded,
                evidenceHash: $row->getString('evidence_hash'),
                reason: $row->getString('reason'),
                createdBy: $row->getString('created_by'),
                createdAt: new DateTimeImmutable($row->getString('created_at')),
            );
        }

        return $snapshots;
    }

    /**
     * Compute the BLAKE2b evidence hash of an entire snapshot payload.
     *
     * @param list<array<string, mixed>> $translationsJson
     * @param list<array<string, mixed>> $blocksJson
     * @param list<string> $taxonomyTermIds
     */
    public static function computeSnapshotHash(
        array $translationsJson,
        array $blocksJson,
        array $taxonomyTermIds,
    ): string {
        $data = json_encode([
            'translations' => $translationsJson,
            'blocks' => $blocksJson,
            'taxonomy_term_ids' => $taxonomyTermIds,
        ], JSON_THROW_ON_ERROR);

        return hash('blake2b', $data);
    }

}
