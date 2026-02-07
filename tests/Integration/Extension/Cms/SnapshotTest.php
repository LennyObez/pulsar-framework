<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\EventStore\ContentSnapshot;
use Pulsar\Extension\Cms\EventStore\ContentSnapshotService;

use function in_array;
use function strlen;

#[CoversClass(ContentSnapshot::class)]
#[CoversClass(ContentSnapshotService::class)]
final class SnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        if (!in_array('blake2b', hash_algos(), true)) {
            self::markTestSkipped('blake2b hash algorithm is not available in this PHP build');
        }
    }

    #[Test]
    public function test_capture_snapshot_includes_all_locales(): void
    {
        $translationsJson = [
            [
                'id' => 'trans-en',
                'locale' => 'en',
                'title' => 'Hello World',
                'slug_segment' => 'hello-world',
                'path' => 'hello-world',
                'body' => '<p>English body</p>',
                'excerpt' => null,
                'meta_title' => null,
                'meta_description' => null,
                'og_image_id' => null,
                'robots' => null,
                'structured_data_overrides' => null,
                'reading_time_minutes' => 3,
            ],
            [
                'id' => 'trans-fr',
                'locale' => 'fr',
                'title' => 'Bonjour le Monde',
                'slug_segment' => 'bonjour-le-monde',
                'path' => 'bonjour-le-monde',
                'body' => '<p>Corps en francais</p>',
                'excerpt' => null,
                'meta_title' => null,
                'meta_description' => null,
                'og_image_id' => null,
                'robots' => null,
                'structured_data_overrides' => null,
                'reading_time_minutes' => 3,
            ],
        ];

        $blocksJson = [
            ['id' => 'block-001', 'locale' => 'en', 'block_type' => 'text', 'sort_order' => 0, 'data' => ['content' => 'Hello']],
        ];

        $taxonomyTermIds = ['term-001', 'term-002'];

        $evidenceHash = ContentSnapshotService::computeSnapshotHash($translationsJson, $blocksJson, $taxonomyTermIds);

        $snapshot = new ContentSnapshot(
            id: 'snap-001',
            contentId: 'content-001',
            snapshotNumber: 1,
            translationsJson: $translationsJson,
            blocksJson: $blocksJson,
            taxonomyTermIds: $taxonomyTermIds,
            evidenceHash: $evidenceHash,
            reason: 'Pre-publication snapshot',
            createdBy: 'user-001',
            createdAt: new DateTimeImmutable(),
        );

        // Verify all locales are captured
        self::assertCount(2, $snapshot->translationsJson);

        $locales = array_column($snapshot->translationsJson, 'locale');
        self::assertContains('en', $locales);
        self::assertContains('fr', $locales);

        // Verify blocks and terms
        self::assertCount(1, $snapshot->blocksJson);
        self::assertCount(2, $snapshot->taxonomyTermIds);
        self::assertSame('Pre-publication snapshot', $snapshot->reason);
        self::assertSame(1, $snapshot->snapshotNumber);
    }

    #[Test]
    public function test_snapshot_evidence_hash_verification(): void
    {
        $translationsJson = [
            [
                'id' => 'trans-en',
                'locale' => 'en',
                'title' => 'Test Article',
                'slug_segment' => 'test-article',
                'path' => 'test-article',
                'body' => '<p>Body</p>',
                'excerpt' => null,
                'meta_title' => null,
                'meta_description' => null,
                'og_image_id' => null,
                'robots' => null,
                'structured_data_overrides' => null,
                'reading_time_minutes' => null,
            ],
        ];

        $blocksJson = [];
        $taxonomyTermIds = [];

        $hash1 = ContentSnapshotService::computeSnapshotHash($translationsJson, $blocksJson, $taxonomyTermIds);

        // Same input produces same hash (deterministic)
        $hash2 = ContentSnapshotService::computeSnapshotHash($translationsJson, $blocksJson, $taxonomyTermIds);
        self::assertSame($hash1, $hash2);

        // Different input produces different hash
        $modifiedTranslations = $translationsJson;
        $modifiedTranslations[0]['title'] = 'Modified Title';

        $hash3 = ContentSnapshotService::computeSnapshotHash($modifiedTranslations, $blocksJson, $taxonomyTermIds);
        self::assertNotSame($hash1, $hash3);

        // Verify hash format (BLAKE2b = 64 bytes = 128 hex chars)
        self::assertSame(128, strlen($hash1));
        self::assertMatchesRegularExpression('/^[a-f0-9]+$/', $hash1);

        // Verify snapshot with correct hash passes verification
        $snapshot = new ContentSnapshot(
            id: 'snap-verify',
            contentId: 'content-001',
            snapshotNumber: 1,
            translationsJson: $translationsJson,
            blocksJson: $blocksJson,
            taxonomyTermIds: $taxonomyTermIds,
            evidenceHash: $hash1,
            reason: 'Verification test',
            createdBy: 'user-001',
            createdAt: new DateTimeImmutable(),
        );

        // Recompute and verify
        $recomputed = ContentSnapshotService::computeSnapshotHash(
            $snapshot->translationsJson,
            $snapshot->blocksJson,
            $snapshot->taxonomyTermIds,
        );

        self::assertSame($snapshot->evidenceHash, $recomputed);
    }
}
