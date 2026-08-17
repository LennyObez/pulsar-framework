<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Tools;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaVisibility;
use Pulsar\Extension\Cms\Tools\ImportAnalysisResult;
use Pulsar\Extension\Cms\Tools\ImportAnalyzer;

#[CoversNothing]
#[CoversClass(ImportAnalysisResult::class)]
final class ImportAnalyzerTest extends TestCase
{
    private ContentRepositoryInterface & \PHPUnit\Framework\MockObject\Stub $contentRepo;
    private MediaRepositoryInterface & \PHPUnit\Framework\MockObject\Stub $mediaRepo;

    protected function setUp(): void
    {
        $this->contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $this->mediaRepo = $this->createStub(MediaRepositoryInterface::class);
    }

    #[Test]
    public function analyzeEmptyBundleReturnsZeroCounts(): void
    {
        $analyzer = new ImportAnalyzer($this->contentRepo, $this->mediaRepo);

        $result = $analyzer->analyze([]);

        self::assertSame(0, $result->totalEntities);
        self::assertSame([], $result->duplicatesByType);
        self::assertSame([], $result->missingDependencies);
        self::assertSame([], $result->entityCounts);
    }

    #[Test]
    public function analyzeCountsContentItems(): void
    {
        $this->contentRepo->method('findByPath')->willReturn(null);

        $analyzer = new ImportAnalyzer($this->contentRepo, $this->mediaRepo);
        $result = $analyzer->analyze([
            'content' => [
                ['slug' => 'page-1', 'locale' => 'en'],
                ['slug' => 'page-2', 'locale' => 'en'],
            ],
        ]);

        self::assertSame(2, $result->entityCounts['content']);
        self::assertSame(2, $result->totalEntities);
        self::assertSame([], $result->duplicatesByType);
    }

    #[Test]
    public function analyzeDetectsContentDuplicates(): void
    {
        $existingContent = $this->buildContent();
        $this->contentRepo->method('findByPath')->willReturn($existingContent);

        $analyzer = new ImportAnalyzer($this->contentRepo, $this->mediaRepo);
        $result = $analyzer->analyze([
            'content' => [
                ['slug' => 'about', 'locale' => 'en'],
                ['slug' => 'contact', 'locale' => 'en'],
            ],
        ]);

        self::assertSame(2, $result->duplicatesByType['content']);
    }

    #[Test]
    public function analyzeSkipsNonArrayContentItems(): void
    {
        $this->contentRepo->method('findByPath')->willReturn(null);

        $analyzer = new ImportAnalyzer($this->contentRepo, $this->mediaRepo);
        $result = $analyzer->analyze([
            'content' => [
                'not-an-array',
                ['slug' => 'valid', 'locale' => 'en'],
            ],
        ]);

        self::assertSame(2, $result->entityCounts['content']);
    }

    #[Test]
    public function analyzeCountsTaxonomiesMenusCommentsUsersConfiguration(): void
    {
        $analyzer = new ImportAnalyzer($this->contentRepo, $this->mediaRepo);
        $result = $analyzer->analyze([
            'taxonomies' => [['name' => 'Category'], ['name' => 'Tag']],
            'menus' => [['location' => 'header']],
            'comments' => [['body' => 'Nice'], ['body' => 'Great'], ['body' => 'Wow']],
            'users' => [['email' => 'a@b.com']],
            'configuration' => [['key' => 'val1'], ['key' => 'val2']],
        ]);

        self::assertSame(2, $result->entityCounts['taxonomies']);
        self::assertSame(1, $result->entityCounts['menus']);
        self::assertSame(3, $result->entityCounts['comments']);
        self::assertSame(1, $result->entityCounts['users']);
        self::assertSame(2, $result->entityCounts['configuration']);
        self::assertSame(9, $result->totalEntities);
    }

    #[Test]
    public function analyzeCountsSettingsAcrossGroups(): void
    {
        $analyzer = new ImportAnalyzer($this->contentRepo, $this->mediaRepo);
        $result = $analyzer->analyze([
            'settings' => [
                'general' => ['site_name' => 'Test', 'tagline' => 'Hello'],
                'seo' => ['robots' => 'index'],
            ],
        ]);

        // 2 + 1 = 3 individual settings
        self::assertSame(3, $result->entityCounts['settings']);
    }

    #[Test]
    public function analyzeDetectsMediaDuplicates(): void
    {
        $existingMedia = $this->buildMediaAsset();
        $this->mediaRepo->method('findById')->willReturn($existingMedia);

        $analyzer = new ImportAnalyzer($this->contentRepo, $this->mediaRepo);
        $result = $analyzer->analyze([
            'media_refs' => [
                ['id' => 'media-1'],
                ['id' => 'media-2'],
            ],
            'media_files' => ['file1.jpg', 'file2.jpg'],
        ]);

        self::assertSame(2, $result->entityCounts['media_refs']);
        self::assertSame(2, $result->duplicatesByType['media_refs']);
        self::assertSame(2, $result->entityCounts['media_files']);
    }

    #[Test]
    public function analyzeDetectsMissingMediaFiles(): void
    {
        $this->mediaRepo->method('findById')->willReturn(null);

        $analyzer = new ImportAnalyzer($this->contentRepo, $this->mediaRepo);
        $result = $analyzer->analyze([
            'media_refs' => [
                ['id' => 'media-1', 'storage_path' => 'uploads/photo.jpg'],
            ],
            // No 'media_files' key => missing dependency
        ]);

        self::assertCount(1, $result->missingDependencies);
        self::assertSame('media_file:uploads/photo.jpg', $result->missingDependencies[0]);
    }

    #[Test]
    public function analyzeNoMissingMediaWhenFilesPresent(): void
    {
        $this->mediaRepo->method('findById')->willReturn(null);

        $analyzer = new ImportAnalyzer($this->contentRepo, $this->mediaRepo);
        $result = $analyzer->analyze([
            'media_refs' => [
                ['id' => 'media-1', 'storage_path' => 'uploads/photo.jpg'],
            ],
            'media_files' => ['uploads/photo.jpg'],
        ]);

        self::assertSame([], $result->missingDependencies);
    }

    #[Test]
    public function analyzeUsesSlugSegmentFallback(): void
    {
        $this->contentRepo->method('findByPath')->willReturn(null);

        $analyzer = new ImportAnalyzer($this->contentRepo, $this->mediaRepo);
        $result = $analyzer->analyze([
            'content' => [
                ['slugSegment' => 'my-page'],
            ],
        ]);

        self::assertSame(1, $result->entityCounts['content']);
        self::assertSame([], $result->duplicatesByType);
    }

    #[Test]
    public function analysisResultToArraySerializesCorrectly(): void
    {
        $result = new ImportAnalysisResult(
            totalEntities: 10,
            duplicatesByType: ['content' => 3],
            missingDependencies: ['media_file:photo.jpg'],
            entityCounts: ['content' => 5, 'media_refs' => 5],
        );

        $array = $result->toArray();

        self::assertSame(10, $array['total_entities']);
        self::assertSame(['content' => 3], $array['duplicates_by_type']);
        self::assertSame(['media_file:photo.jpg'], $array['missing_dependencies']);
        self::assertSame(['content' => 5, 'media_refs' => 5], $array['entity_counts']);
    }

    private function buildContent(): Content
    {
        $now = new DateTimeImmutable();

        return new Content(
            id: 'content-1',
            tenantId: null,
            contentType: ContentType::Page,
            authorId: 'author-1',
            status: PublishingStatus::Published,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: $now,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Open,
            dataClassification: DataClassification::Public,
        );
    }

    private function buildMediaAsset(): MediaAsset
    {
        $now = new DateTimeImmutable();

        return new MediaAsset(
            id: 'media-1',
            tenantId: null,
            uploaderId: 'user-1',
            filename: 'photo.jpg',
            storagePath: 'uploads/photo.jpg',
            disk: 'local',
            mimeType: 'image/jpeg',
            fileSize: 1024,
            fileHash: 'abc123',
            width: 800,
            height: 600,
            exifData: null,
            altTextDefault: 'A photo',
            visibility: MediaVisibility::Public,
            dataClassification: DataClassification::Public,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );
    }
}
