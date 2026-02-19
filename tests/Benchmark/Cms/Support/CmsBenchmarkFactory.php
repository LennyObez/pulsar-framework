<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Cms\Support;

use DateTimeImmutable;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentBlock;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\FieldRegistry\ContentFieldValue;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Navigation\BreadcrumbItem;
use Pulsar\Extension\Cms\Search\SearchResult;

use function bin2hex;
use function dechex;
use function hash;
use function hexdec;
use function microtime;
use function random_bytes;
use function sprintf;
use function str_pad;
use function str_repeat;
use function substr;

use const STR_PAD_LEFT;

/**
 * Factory for creating CMS domain objects in benchmark scenarios.
 *
 * All objects are created in-memory with no I/O overhead.
 */
final class CmsBenchmarkFactory
{
    private int $sequence = 0;

    public function generateUuidV7(): string
    {
        $time = (int) (microtime(true) * 1000) + $this->sequence++;
        $hex = str_pad(dechex($time), 12, '0', STR_PAD_LEFT);
        $random = bin2hex(random_bytes(8));

        return sprintf(
            '%s-%s-7%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($random, 0, 3),
            dechex(0x80 | (hexdec(substr($random, 3, 2)) & 0x3F)) . substr($random, 5, 2),
            substr($random, 7, 12),
        );
    }

    public function createPublishedContent(
        ?string $id = null,
        ContentType $contentType = ContentType::Article,
        ?string $tenantId = null,
    ): Content {
        $now = new DateTimeImmutable();

        return new Content(
            id: $id ?? $this->generateUuidV7(),
            tenantId: $tenantId,
            contentType: $contentType,
            authorId: $this->generateUuidV7(),
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

    public function createTranslation(
        string $contentId,
        string $locale = 'en',
        ?string $slug = null,
    ): ContentTranslation {
        $id = $this->generateUuidV7();
        $slug ??= 'bench-article-' . $this->sequence;
        $bodyHtml = '<p>' . str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 20) . '</p>';

        return new ContentTranslation(
            id: $id,
            contentId: $contentId,
            locale: $locale,
            title: 'Benchmark Article ' . $this->sequence,
            slugSegment: $slug,
            path: $slug,
            body: $bodyHtml,
            excerpt: 'Benchmark article excerpt for performance testing.',
            metaTitle: 'Benchmark Article - Performance Test',
            metaDescription: 'This article is used for CMS performance benchmarking.',
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: 3,
            bodyPlaintext: str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 20),
            headingsText: "Introduction\nGetting Started\nConclusion",
            customFieldsText: '',
            taxonomyTermsText: 'technology php performance',
        );
    }

    /**
     * @return list<ContentBlock>
     */
    public function createBlocks(string $contentId, string $locale = 'en', int $count = 3): array
    {
        $blocks = [];

        for ($i = 0; $i < $count; $i++) {
            $blocks[] = ContentBlock::text(
                id: $this->generateUuidV7(),
                contentId: $contentId,
                locale: $locale,
                sortOrder: $i,
                data: ['content' => '<p>Block content paragraph ' . $i . '</p>'],
            );
        }

        return $blocks;
    }

    /**
     * @return list<ContentFieldValue>
     */
    public function createFieldValues(string $contentId, string $locale = 'en', int $count = 2): array
    {
        $values = [];

        for ($i = 0; $i < $count; $i++) {
            $values[] = new ContentFieldValue(
                id: $this->generateUuidV7(),
                contentId: $contentId,
                fieldId: $this->generateUuidV7(),
                locale: $locale,
                valueString: 'field-value-' . $i,
                valueInt: null,
                valueFloat: null,
                valueBool: null,
                valueDatetime: null,
                valueJson: null,
            );
        }

        return $values;
    }

    /**
     * @return list<BreadcrumbItem>
     */
    public function createBreadcrumbs(int $depth = 3): array
    {
        $items = [new BreadcrumbItem(label: 'Home', url: '/', isCurrent: false)];

        for ($i = 1; $i < $depth - 1; $i++) {
            $items[] = new BreadcrumbItem(
                label: 'Category ' . $i,
                url: '/category-' . $i,
                isCurrent: false,
            );
        }

        $items[] = new BreadcrumbItem(label: 'Current Page', url: '/current', isCurrent: true);

        return $items;
    }

    public function createMediaAsset(?string $id = null): MediaAsset
    {
        $id ??= $this->generateUuidV7();
        $fileHash = hash('sha256', 'bench-media-' . $id);

        return MediaAsset::create(
            id: $id,
            uploaderId: $this->generateUuidV7(),
            filename: 'benchmark-image.jpg',
            storagePath: 'uploads/2026/02/' . $fileHash . '.jpg',
            disk: 'local',
            mimeType: 'image/jpeg',
            fileSize: 5_242_880,
            fileHash: $fileHash,
            width: 1920,
            height: 1080,
        );
    }

    /**
     * Create a search result with the specified number of items.
     */
    public function createSearchResult(int $itemCount = 20, float $tookMs = 15.0): SearchResult
    {
        $items = [];

        for ($i = 0; $i < $itemCount; $i++) {
            $items[] = $this->createPublishedContent();
        }

        return new SearchResult(
            items: $items,
            total: $itemCount * 5,
            query: 'benchmark search query',
            suggestions: ['alternative query', 'related search'],
            tookMs: $tookMs,
        );
    }

    /**
     * Create a paginated result of published content.
     *
     * @return PaginationResult<Content>
     */
    public function createPaginatedContent(int $count = 20, int $total = 100): PaginationResult
    {
        $items = [];

        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->createPublishedContent();
        }

        return new PaginationResult(
            items: $items,
            total: $total,
            hasMore: $total > $count,
            perPage: $count,
            currentPage: 1,
            lastPage: (int) ceil($total / $count),
        );
    }

    /**
     * Generate a large JSON import bundle for import benchmarks.
     */
    public function generateImportBundle(int $contentCount = 1000): string
    {
        $items = [];

        for ($i = 0; $i < $contentCount; $i++) {
            $items[] = [
                'type' => 'article',
                'locale' => 'en',
                'title' => 'Imported Article ' . $i,
                'slug' => 'imported-article-' . $i,
                'body' => '<p>' . str_repeat('Content body for import benchmark. ', 10) . '</p>',
                'excerpt' => 'Import benchmark excerpt ' . $i,
                'meta_title' => 'Imported Article ' . $i,
                'meta_description' => 'Description for imported article ' . $i,
                'status' => 'draft',
            ];
        }

        return json_encode([
            'version' => '1.0',
            'content' => $items,
        ], JSON_THROW_ON_ERROR);
    }
}
