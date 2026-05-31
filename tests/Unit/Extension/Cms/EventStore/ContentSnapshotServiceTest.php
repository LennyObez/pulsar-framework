<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\EventStore;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\EventStore\ContentSnapshot;
use Pulsar\Extension\Cms\EventStore\ContentSnapshotService;
use ReflectionClass;

use function strlen;

#[CoversClass(ContentSnapshotService::class)]
#[CoversClass(ContentSnapshot::class)]
final class ContentSnapshotServiceTest extends TestCase
{
    private const string CONTENT_ID = '01912345-6789-7abc-8def-0123456789ab';

    // ── Snapshot entity ──────────────────────────────────────────────

    #[Test]
    public function contentSnapshotConstruction(): void
    {
        $now = new DateTimeImmutable();
        $translationsJson = [
            [
                'id' => 'tr-001',
                'locale' => 'en',
                'title' => 'Hello',
                'slug_segment' => 'hello',
                'path' => 'hello',
                'body' => '<p>World</p>',
                'excerpt' => null,
                'meta_title' => null,
                'meta_description' => null,
                'og_image_id' => null,
                'robots' => null,
                'structured_data_overrides' => null,
                'reading_time_minutes' => 1,
            ],
        ];

        $blocksJson = [
            [
                'id' => 'blk-001',
                'locale' => 'en',
                'block_type' => 'text',
                'sort_order' => 0,
                'data' => ['content' => 'Text block'],
            ],
        ];

        $taxonomyTermIds = ['term-001', 'term-002'];

        $hash = ContentSnapshotService::computeSnapshotHash($translationsJson, $blocksJson, $taxonomyTermIds);

        $snapshot = new ContentSnapshot(
            id: 'snap-001',
            contentId: self::CONTENT_ID,
            snapshotNumber: 1,
            translationsJson: $translationsJson,
            blocksJson: $blocksJson,
            taxonomyTermIds: $taxonomyTermIds,
            evidenceHash: $hash,
            reason: 'Published',
            createdBy: 'user-001',
            createdAt: $now,
        );

        self::assertSame('snap-001', $snapshot->id);
        self::assertSame(self::CONTENT_ID, $snapshot->contentId);
        self::assertSame(1, $snapshot->snapshotNumber);
        self::assertSame($translationsJson, $snapshot->translationsJson);
        self::assertSame($blocksJson, $snapshot->blocksJson);
        self::assertSame($taxonomyTermIds, $snapshot->taxonomyTermIds);
        self::assertSame($hash, $snapshot->evidenceHash);
        self::assertSame('Published', $snapshot->reason);
        self::assertSame('user-001', $snapshot->createdBy);
        self::assertSame($now, $snapshot->createdAt);
    }

    // ── All-locale capture verification ──────────────────────────────

    #[Test]
    public function snapshotCapturesMultipleLocales(): void
    {
        $translations = [
            ['id' => 'tr-en', 'locale' => 'en', 'title' => 'Hello', 'slug_segment' => 'hello', 'path' => 'hello', 'body' => '<p>Hi</p>', 'excerpt' => null, 'meta_title' => null, 'meta_description' => null, 'og_image_id' => null, 'robots' => null, 'structured_data_overrides' => null, 'reading_time_minutes' => 1],
            ['id' => 'tr-fr', 'locale' => 'fr', 'title' => 'Bonjour', 'slug_segment' => 'bonjour', 'path' => 'bonjour', 'body' => '<p>Salut</p>', 'excerpt' => null, 'meta_title' => null, 'meta_description' => null, 'og_image_id' => null, 'robots' => null, 'structured_data_overrides' => null, 'reading_time_minutes' => 1],
            ['id' => 'tr-de', 'locale' => 'de', 'title' => 'Hallo', 'slug_segment' => 'hallo', 'path' => 'hallo', 'body' => '<p>Hi</p>', 'excerpt' => null, 'meta_title' => null, 'meta_description' => null, 'og_image_id' => null, 'robots' => null, 'structured_data_overrides' => null, 'reading_time_minutes' => 1],
        ];

        $hash = ContentSnapshotService::computeSnapshotHash($translations, [], []);

        $snapshot = new ContentSnapshot(
            id: 'snap-multi',
            contentId: self::CONTENT_ID,
            snapshotNumber: 1,
            translationsJson: $translations,
            blocksJson: [],
            taxonomyTermIds: [],
            evidenceHash: $hash,
            reason: 'All-locale capture',
            createdBy: 'user-001',
            createdAt: new DateTimeImmutable(),
        );

        self::assertCount(3, $snapshot->translationsJson);
        $locales = array_column($snapshot->translationsJson, 'locale');
        self::assertContains('en', $locales);
        self::assertContains('fr', $locales);
        self::assertContains('de', $locales);
    }

    // ── Evidence hash computation ────────────────────────────────────

    #[Test]
    public function computeSnapshotHashReturnsBlake2bHex(): void
    {
        $hash = ContentSnapshotService::computeSnapshotHash(
            [['title' => 'Test']],
            [['block_type' => 'text']],
            ['term-001'],
        );

        self::assertNotEmpty($hash);
        self::assertSame(128, strlen($hash)); // BLAKE2b = 64 bytes = 128 hex chars
        self::assertMatchesRegularExpression('/^[a-f0-9]+$/', $hash);
    }

    #[Test]
    public function computeSnapshotHashDeterministic(): void
    {
        $translations = [['title' => 'A']];
        $blocks = [['type' => 'text']];
        $terms = ['t1'];

        $hash1 = ContentSnapshotService::computeSnapshotHash($translations, $blocks, $terms);
        $hash2 = ContentSnapshotService::computeSnapshotHash($translations, $blocks, $terms);

        self::assertSame($hash1, $hash2);
    }

    #[Test]
    public function computeSnapshotHashChangesWithDifferentTranslations(): void
    {
        $hash1 = ContentSnapshotService::computeSnapshotHash([['title' => 'A']], [], []);
        $hash2 = ContentSnapshotService::computeSnapshotHash([['title' => 'B']], [], []);

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function computeSnapshotHashChangesWithDifferentBlocks(): void
    {
        $hash1 = ContentSnapshotService::computeSnapshotHash([], [['type' => 'text']], []);
        $hash2 = ContentSnapshotService::computeSnapshotHash([], [['type' => 'image']], []);

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function computeSnapshotHashChangesWithDifferentTerms(): void
    {
        $hash1 = ContentSnapshotService::computeSnapshotHash([], [], ['term-1']);
        $hash2 = ContentSnapshotService::computeSnapshotHash([], [], ['term-2']);

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function computeSnapshotHashEmptyInputs(): void
    {
        $hash = ContentSnapshotService::computeSnapshotHash([], [], []);
        self::assertNotEmpty($hash);
        self::assertSame(128, strlen($hash));
    }

    #[Test]
    public function contentSnapshotIsReadonly(): void
    {
        $reflection = new ReflectionClass(ContentSnapshot::class);
        self::assertTrue($reflection->isReadOnly());
    }
}
