<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Tools;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Internal\Tools\CsvContentExporter;

use function assert;

#[CoversClass(CsvContentExporter::class)]
final class CsvContentExporterTest extends TestCase
{
    private CsvContentExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new CsvContentExporter();
    }

    #[Test]
    public function exportEmptyArrayReturnsHeaderOnly(): void
    {
        $csv = $this->exporter->export([]);

        $lines = $this->parseCsvLines($csv);
        self::assertCount(1, $lines);
        self::assertSame('id', $lines[0][0]);
        self::assertSame('content_type', $lines[0][1]);
        self::assertSame('status', $lines[0][2]);
        self::assertSame('locale', $lines[0][3]);
        self::assertSame('title', $lines[0][4]);
        self::assertSame('slug', $lines[0][5]);
        self::assertSame('path', $lines[0][6]);
        self::assertSame('body', $lines[0][7]);
        self::assertSame('excerpt', $lines[0][8]);
        self::assertSame('meta_title', $lines[0][9]);
        self::assertSame('meta_description', $lines[0][10]);
        self::assertSame('author_id', $lines[0][11]);
        self::assertSame('created_at', $lines[0][12]);
        self::assertSame('published_at', $lines[0][13]);
    }

    #[Test]
    public function exportSingleItemProducesCorrectRow(): void
    {
        $createdAt = new DateTimeImmutable('2024-06-15T10:00:00+00:00');
        $publishedAt = new DateTimeImmutable('2024-06-16T12:00:00+00:00');

        $content = new Content(
            id: 'content-1',
            tenantId: null,
            contentType: ContentType::Article,
            authorId: 'author-1',
            status: PublishingStatus::Published,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: $publishedAt,
            createdAt: $createdAt,
            updatedAt: $createdAt,
            deletedAt: null,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Open,
            dataClassification: DataClassification::Public,
        );

        $translation = new ContentTranslation(
            id: 'trans-1',
            contentId: 'content-1',
            locale: 'en',
            title: 'Test Article',
            slugSegment: 'test-article',
            path: 'blog/test-article',
            body: '<p>Hello World</p>',
            excerpt: 'A test article',
            metaTitle: 'Test Article - Blog',
            metaDescription: 'A description of the test article.',
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: 2,
            bodyPlaintext: 'Hello World',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );

        $csv = $this->exporter->export([['content' => $content, 'translation' => $translation]]);

        $lines = $this->parseCsvLines($csv);
        self::assertCount(2, $lines); // header + 1 row

        $row = $lines[1];
        self::assertSame('content-1', $row[0]);
        self::assertSame('article', $row[1]);
        self::assertSame('published', $row[2]);
        self::assertSame('en', $row[3]);
        self::assertSame('Test Article', $row[4]);
        self::assertSame('test-article', $row[5]);
        self::assertSame('blog/test-article', $row[6]);
        self::assertSame('<p>Hello World</p>', $row[7]);
        self::assertSame('A test article', $row[8]);
        self::assertSame('Test Article - Blog', $row[9]);
        self::assertSame('A description of the test article.', $row[10]);
        self::assertSame('author-1', $row[11]);
        self::assertStringContainsString('2024-06-15', $row[12]);
        self::assertStringContainsString('2024-06-16', $row[13]);
    }

    #[Test]
    public function exportMultipleItemsProducesMultipleRows(): void
    {
        $items = [];
        for ($i = 1; $i <= 3; $i++) {
            $items[] = $this->createItem("content-$i", "Article $i", "article-$i");
        }

        $csv = $this->exporter->export($items);

        $lines = $this->parseCsvLines($csv);
        self::assertCount(4, $lines); // header + 3 rows
    }

    #[Test]
    public function exportHandlesNullExcerptAsEmptyString(): void
    {
        $item = $this->createItem('c-1', 'Title', 'slug', excerpt: null);

        $csv = $this->exporter->export([$item]);

        $lines = $this->parseCsvLines($csv);
        self::assertSame('', $lines[1][8]); // excerpt column
    }

    #[Test]
    public function exportHandlesNullPublishedAtAsEmptyString(): void
    {
        $now = new DateTimeImmutable();
        $content = new Content(
            id: 'c-draft',
            tenantId: null,
            contentType: ContentType::Article,
            authorId: 'a-1',
            status: PublishingStatus::Draft,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: null,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Open,
            dataClassification: DataClassification::Public,
        );

        $translation = new ContentTranslation(
            id: 't-1',
            contentId: 'c-draft',
            locale: 'en',
            title: 'Draft',
            slugSegment: 'draft',
            path: 'draft',
            body: '<p>Draft</p>',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: null,
            bodyPlaintext: 'Draft',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );

        $csv = $this->exporter->export([['content' => $content, 'translation' => $translation]]);

        $lines = $this->parseCsvLines($csv);
        self::assertSame('', $lines[1][13]); // published_at column
    }

    #[Test]
    public function exportHandlesNullMetaFieldsAsEmptyStrings(): void
    {
        $item = $this->createItem('c-1', 'Title', 'slug', metaTitle: null, metaDescription: null);

        $csv = $this->exporter->export([$item]);

        $lines = $this->parseCsvLines($csv);
        self::assertSame('', $lines[1][9]);  // meta_title
        self::assertSame('', $lines[1][10]); // meta_description
    }

    #[Test]
    public function exportHandlesHtmlInBody(): void
    {
        $item = $this->createItem('c-1', 'Title', 'slug', body: '<p>Hello</p><blockquote>Quote</blockquote>');

        $csv = $this->exporter->export([$item]);

        $lines = $this->parseCsvLines($csv);
        self::assertSame('<p>Hello</p><blockquote>Quote</blockquote>', $lines[1][7]);
    }

    #[Test]
    public function exportHandlesCommasInFields(): void
    {
        $item = $this->createItem('c-1', 'Title, with comma', 'slug');

        $csv = $this->exporter->export([$item]);

        $lines = $this->parseCsvLines($csv);
        self::assertSame('Title, with comma', $lines[1][4]);
    }

    #[Test]
    public function exportHandlesPageContentType(): void
    {
        $now = new DateTimeImmutable();
        $content = new Content(
            id: 'c-page',
            tenantId: null,
            contentType: ContentType::Page,
            authorId: 'a-1',
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
            commentPolicy: CommentPolicy::Closed,
            dataClassification: DataClassification::Public,
        );

        $translation = new ContentTranslation(
            id: 't-1',
            contentId: 'c-page',
            locale: 'en',
            title: 'About Us',
            slugSegment: 'about-us',
            path: 'about-us',
            body: '<p>About</p>',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: null,
            bodyPlaintext: 'About',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );

        $csv = $this->exporter->export([['content' => $content, 'translation' => $translation]]);

        $lines = $this->parseCsvLines($csv);
        self::assertSame('page', $lines[1][1]);
    }

    #[Test]
    public function exportReturnsValidCsvString(): void
    {
        $csv = $this->exporter->export([]);

        self::assertNotEmpty($csv);
    }

    /**
     * @return list<list<string>>
     */
    private function parseCsvLines(string $csv): array
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, $csv);
        rewind($stream);

        $lines = [];
        while (($row = fgetcsv($stream, escape: '\\')) !== false) {
            $lines[] = array_map(static fn(?string $v): string => $v ?? '', $row);
        }

        fclose($stream);

        return $lines;
    }

    /**
     * @return array{content: Content, translation: ContentTranslation}
     */
    private function createItem(
        string $id,
        string $title,
        string $slug,
        ?string $excerpt = 'An excerpt',
        ?string $metaTitle = 'Meta Title',
        ?string $metaDescription = 'Meta Description',
        string $body = '<p>Body</p>',
    ): array {
        $now = new DateTimeImmutable();

        $content = new Content(
            id: $id,
            tenantId: null,
            contentType: ContentType::Article,
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

        $translation = new ContentTranslation(
            id: "t-$id",
            contentId: $id,
            locale: 'en',
            title: $title,
            slugSegment: $slug,
            path: "blog/$slug",
            body: $body,
            excerpt: $excerpt,
            metaTitle: $metaTitle,
            metaDescription: $metaDescription,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: 3,
            bodyPlaintext: strip_tags($body),
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );

        return ['content' => $content, 'translation' => $translation];
    }
}
