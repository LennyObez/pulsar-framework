<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Tools;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentBlock;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Internal\Tools\CsvContentExporter;
use Pulsar\Extension\Cms\Internal\Tools\CsvContentImporter;
use Pulsar\Extension\Cms\Internal\Tools\MarkdownExporter;
use Pulsar\Extension\Cms\Internal\Tools\MarkdownImporter;

#[CoversClass(MarkdownExporter::class)]
#[CoversClass(MarkdownImporter::class)]
#[CoversClass(CsvContentExporter::class)]
#[CoversClass(CsvContentImporter::class)]
final class ImportExportFormatsTest extends TestCase
{
    private MarkdownExporter $mdExporter;
    private MarkdownImporter $mdImporter;
    private CsvContentExporter $csvExporter;
    private CsvContentImporter $csvImporter;

    protected function setUp(): void
    {
        $this->mdExporter = new MarkdownExporter();
        $this->mdImporter = new MarkdownImporter();
        $this->csvExporter = new CsvContentExporter();
        $this->csvImporter = new CsvContentImporter();
    }

    #[Test]
    public function markdown_round_trip_preserves_data(): void
    {
        [$content, $translation] = $this->createContentPair(
            title: 'Hello World',
            slug: 'hello-world',
            body: 'This is the body content.',
        );

        $md = $this->mdExporter->export($content, $translation);
        $parsed = $this->mdImporter->parse($md);

        self::assertSame('Hello World', $parsed['translation']['title']);
        self::assertSame('hello-world', $parsed['translation']['slug']);
        self::assertSame('This is the body content.', $parsed['translation']['body']);
        self::assertSame('article', $parsed['content']['content_type']);
        self::assertSame('published', $parsed['content']['status']);
        self::assertSame('en', $parsed['translation']['locale']);
    }

    #[Test]
    public function markdown_round_trip_with_blocks(): void
    {
        [$content, $translation] = $this->createContentPair(
            title: 'Block Test',
            slug: 'block-test',
            body: 'Main body.',
        );

        $now = new DateTimeImmutable();
        $blocks = [
            new ContentBlock(
                id: 'block-001',
                contentId: $content->id,
                locale: 'en',
                blockType: 'text',
                sortOrder: 0,
                data: ['content' => 'Block text content'],
                createdAt: $now,
                updatedAt: $now,
            ),
            new ContentBlock(
                id: 'block-002',
                contentId: $content->id,
                locale: 'en',
                blockType: 'image',
                sortOrder: 1,
                data: ['media_id' => 'img-001', 'alt' => 'Test image'],
                createdAt: $now,
                updatedAt: $now,
            ),
        ];

        $md = $this->mdExporter->export($content, $translation, $blocks);
        $parsed = $this->mdImporter->parse($md);

        self::assertSame('Block Test', $parsed['translation']['title']);
        self::assertSame('Main body.', $parsed['translation']['body']);
        self::assertCount(2, $parsed['blocks']);
        self::assertSame('text', $parsed['blocks'][0]['block_type']);
        self::assertSame(0, $parsed['blocks'][0]['sort_order']);
        self::assertIsArray($parsed['blocks'][0]['data']);
        self::assertSame('Block text content', $parsed['blocks'][0]['data']['content']);
        self::assertSame('image', $parsed['blocks'][1]['block_type']);
        self::assertSame(1, $parsed['blocks'][1]['sort_order']);
        self::assertIsArray($parsed['blocks'][1]['data']);
        self::assertSame('img-001', $parsed['blocks'][1]['data']['media_id']);
    }

    #[Test]
    public function markdown_with_special_characters_in_yaml(): void
    {
        [$content, $translation] = $this->createContentPair(
            title: 'Title with: colons and "quotes"',
            slug: 'special-chars',
            body: 'Body content here.',
            metaDescription: 'Description with #hash and [brackets]',
        );

        $md = $this->mdExporter->export($content, $translation);
        $parsed = $this->mdImporter->parse($md);

        self::assertSame('Title with: colons and "quotes"', $parsed['translation']['title']);
        self::assertSame('Description with #hash and [brackets]', $parsed['translation']['meta_description']);
    }

    #[Test]
    public function markdown_multi_document_export_and_import(): void
    {
        [$content1, $translation1] = $this->createContentPair(
            title: 'First Article',
            slug: 'first-article',
            body: 'First body.',
        );

        [$content2, $translation2] = $this->createContentPair(
            title: 'Second Article',
            slug: 'second-article',
            body: 'Second body.',
        );

        $md = $this->mdExporter->exportAll([
            ['content' => $content1, 'translation' => $translation1, 'blocks' => []],
            ['content' => $content2, 'translation' => $translation2, 'blocks' => []],
        ]);

        $parsed = $this->mdImporter->parseAll($md);

        self::assertCount(2, $parsed);
        self::assertSame('First Article', $parsed[0]['translation']['title']);
        self::assertSame('first-article', $parsed[0]['translation']['slug']);
        self::assertSame('Second Article', $parsed[1]['translation']['title']);
        self::assertSame('second-article', $parsed[1]['translation']['slug']);
    }

    #[Test]
    public function markdown_import_with_missing_fields_uses_defaults(): void
    {
        $md = "---\ntitle: Minimal Post\nslug: minimal\n---\nJust a body.";

        $parsed = $this->mdImporter->parse($md);

        self::assertSame('Minimal Post', $parsed['translation']['title']);
        self::assertSame('minimal', $parsed['translation']['slug']);
        self::assertSame('Just a body.', $parsed['translation']['body']);
        self::assertSame('page', $parsed['content']['content_type']);
        self::assertSame('draft', $parsed['content']['status']);
        self::assertSame('en', $parsed['translation']['locale']);
        self::assertSame('system', $parsed['content']['author_id']);
        self::assertNull($parsed['translation']['excerpt']);
        self::assertNull($parsed['translation']['meta_title']);
        self::assertNull($parsed['translation']['meta_description']);
        self::assertSame([], $parsed['blocks']);
    }

    #[Test]
    public function csv_round_trip_preserves_data(): void
    {
        [$content, $translation] = $this->createContentPair(
            title: 'CSV Test',
            slug: 'csv-test',
            body: '<p>HTML body content</p>',
        );

        $csv = $this->csvExporter->export([
            ['content' => $content, 'translation' => $translation],
        ]);

        $parsed = $this->csvImporter->parse($csv);

        self::assertCount(1, $parsed);
        self::assertSame('CSV Test', $parsed[0]['translation']['title']);
        self::assertSame('csv-test', $parsed[0]['translation']['slug']);
        self::assertSame('<p>HTML body content</p>', $parsed[0]['translation']['body']);
        self::assertSame('article', $parsed[0]['content']['content_type']);
        self::assertSame('published', $parsed[0]['content']['status']);
    }

    #[Test]
    public function csv_with_commas_and_quotes_in_body(): void
    {
        [$content, $translation] = $this->createContentPair(
            title: 'Title with, commas',
            slug: 'commas-test',
            body: 'Body with "double quotes", commas, and special chars.',
        );

        $csv = $this->csvExporter->export([
            ['content' => $content, 'translation' => $translation],
        ]);

        $parsed = $this->csvImporter->parse($csv);

        self::assertCount(1, $parsed);
        self::assertSame('Title with, commas', $parsed[0]['translation']['title']);
        self::assertSame('Body with "double quotes", commas, and special chars.', $parsed[0]['translation']['body']);
    }

    #[Test]
    public function csv_multiple_items_round_trip(): void
    {
        [$content1, $translation1] = $this->createContentPair(
            title: 'First',
            slug: 'first',
            body: 'First body.',
        );

        [$content2, $translation2] = $this->createContentPair(
            title: 'Second',
            slug: 'second',
            body: 'Second body.',
        );

        $csv = $this->csvExporter->export([
            ['content' => $content1, 'translation' => $translation1],
            ['content' => $content2, 'translation' => $translation2],
        ]);

        $parsed = $this->csvImporter->parse($csv);

        self::assertCount(2, $parsed);
        self::assertSame('First', $parsed[0]['translation']['title']);
        self::assertSame('Second', $parsed[1]['translation']['title']);
    }

    #[Test]
    public function csv_import_with_empty_optional_fields(): void
    {
        $csv = "id,content_type,status,locale,title,slug,path,body,excerpt,meta_title,meta_description,author_id,created_at,published_at\n";
        $csv .= "test-id,page,draft,en,Bare Minimum,bare-min,bare-min,Body text,,,,,,\n";

        $parsed = $this->csvImporter->parse($csv);

        self::assertCount(1, $parsed);
        self::assertSame('Bare Minimum', $parsed[0]['translation']['title']);
        self::assertSame('bare-min', $parsed[0]['translation']['slug']);
        self::assertSame('page', $parsed[0]['content']['content_type']);
        self::assertNull($parsed[0]['translation']['excerpt']);
        self::assertNull($parsed[0]['translation']['meta_title']);
        self::assertNull($parsed[0]['translation']['meta_description']);
    }

    #[Test]
    public function csv_import_with_empty_input_returns_empty_array(): void
    {
        $parsed = $this->csvImporter->parse('');

        self::assertSame([], $parsed);
    }

    #[Test]
    public function markdown_import_plain_text_without_frontmatter(): void
    {
        $md = 'Just plain text without any frontmatter.';

        $parsed = $this->mdImporter->parse($md);

        self::assertSame('Just plain text without any frontmatter.', $parsed['translation']['body']);
        self::assertSame('page', $parsed['content']['content_type']);
        self::assertSame('', $parsed['translation']['title']);
    }

    #[Test]
    public function csv_header_only_returns_empty(): void
    {
        $csv = "id,content_type,status,locale,title,slug,path,body,excerpt,meta_title,meta_description,author_id,created_at,published_at\n";

        $parsed = $this->csvImporter->parse($csv);

        self::assertSame([], $parsed);
    }

    #[Test]
    public function markdown_export_omits_null_fields(): void
    {
        [$content, $translation] = $this->createContentPair(
            title: 'No Meta',
            slug: 'no-meta',
            body: 'Body only.',
        );

        // The content has no published_at (draft state) won't apply here since we use Published status,
        // but meta_title and meta_description can be null.
        $md = $this->mdExporter->export($content, $translation);

        // Verify that null fields are not present in the frontmatter
        self::assertStringNotContainsString('meta_title:', $md);
    }

    /**
     * @return array{Content, ContentTranslation}
     */
    private function createContentPair(
        string $title,
        string $slug,
        string $body,
        string $locale = 'en',
        ?string $metaDescription = null,
    ): array {
        $now = new DateTimeImmutable('2025-06-15T12:00:00+00:00');
        $contentId = 'test-content-' . $slug;

        $content = new Content(
            id: $contentId,
            tenantId: null,
            contentType: ContentType::Article,
            authorId: 'author-001',
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
            commentPolicy: \Pulsar\Extension\Cms\Content\CommentPolicy::Inherit,
            dataClassification: \Pulsar\Extension\Cms\Content\DataClassification::Public,
        );

        $translation = new ContentTranslation(
            id: 'trans-' . $slug,
            contentId: $contentId,
            locale: $locale,
            title: $title,
            slugSegment: $slug,
            path: $slug,
            body: $body,
            excerpt: null,
            metaTitle: null,
            metaDescription: $metaDescription,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: null,
            bodyPlaintext: '',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );

        return [$content, $translation];
    }
}
