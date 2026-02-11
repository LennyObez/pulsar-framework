<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Internal\Tools;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentBlock;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Internal\Tools\MarkdownExporter;

#[CoversClass(MarkdownExporter::class)]
final class MarkdownExporterTest extends TestCase
{
    private MarkdownExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new MarkdownExporter();
    }

    #[Test]
    public function exportProducesFrontmatterAndBody(): void
    {
        $content = $this->buildContent();
        $translation = $this->buildTranslation();

        $result = $this->exporter->export($content, $translation);

        self::assertStringStartsWith("---\n", $result);
        self::assertStringContainsString('title: Hello World', $result);
        self::assertStringContainsString('slug: hello-world', $result);
        self::assertStringContainsString('content_type: page', $result);
        self::assertStringContainsString('status: published', $result);
        self::assertStringContainsString("---\n\n", $result);
        self::assertStringContainsString('<p>Body content</p>', $result);
    }

    #[Test]
    public function exportIncludesMetaFieldsWhenPresent(): void
    {
        $content = $this->buildContent();
        $translation = $this->buildTranslation(metaTitle: 'SEO Title', metaDescription: 'SEO Desc');

        $result = $this->exporter->export($content, $translation);

        self::assertStringContainsString('meta_title: SEO Title', $result);
        self::assertStringContainsString('meta_description: SEO Desc', $result);
    }

    #[Test]
    public function exportOmitsNullFields(): void
    {
        $content = $this->buildContent(publishedAt: null);
        $translation = $this->buildTranslation(metaTitle: null);

        $result = $this->exporter->export($content, $translation);

        self::assertStringNotContainsString('published_at:', $result);
        self::assertStringNotContainsString('meta_title:', $result);
    }

    #[Test]
    public function exportIncludesBlocks(): void
    {
        $content = $this->buildContent();
        $translation = $this->buildTranslation();
        $blocks = [
            ContentBlock::text('block-1', 'content-1', 'en', 0, ['content' => 'First block']),
            ContentBlock::text('block-2', 'content-1', 'en', 1, ['content' => 'Second block']),
        ];

        $result = $this->exporter->export($content, $translation, $blocks);

        self::assertStringContainsString('<!-- block:text:0 -->', $result);
        self::assertStringContainsString('<!-- block:text:1 -->', $result);
        self::assertStringContainsString('"First block"', $result);
        self::assertStringContainsString('"Second block"', $result);
    }

    #[Test]
    public function exportAllConcatenatesDocuments(): void
    {
        $content = $this->buildContent();
        $translation1 = $this->buildTranslation(title: 'Page One');
        $translation2 = $this->buildTranslation(title: 'Page Two');

        $result = $this->exporter->exportAll([
            ['content' => $content, 'translation' => $translation1, 'blocks' => []],
            ['content' => $content, 'translation' => $translation2, 'blocks' => []],
        ]);

        self::assertStringContainsString('title: Page One', $result);
        self::assertStringContainsString('title: Page Two', $result);
        // Documents separated by standalone ---
        self::assertStringContainsString("\n---\n", $result);
    }

    #[Test]
    public function exportEscapesSpecialYamlCharacters(): void
    {
        $content = $this->buildContent();
        $translation = $this->buildTranslation(title: 'Title: with "special" chars');

        $result = $this->exporter->export($content, $translation);

        // Value with : should be quoted
        self::assertStringContainsString('title: "Title: with \\"special\\" chars"', $result);
    }

    #[Test]
    public function exportWithNoBlocksOmitsBlockSection(): void
    {
        $content = $this->buildContent();
        $translation = $this->buildTranslation();

        $result = $this->exporter->export($content, $translation, []);

        self::assertStringNotContainsString('<!-- block:', $result);
    }

    private function buildContent(DateTimeImmutable|null|false $publishedAt = false): Content
    {
        $now = new DateTimeImmutable('2024-01-15T10:00:00+00:00');

        return new Content(
            id: 'content-1',
            tenantId: null,
            contentType: ContentType::Page,
            authorId: 'author-1',
            status: PublishingStatus::Published,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: $publishedAt === false ? $now : $publishedAt,
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

    private function buildTranslation(
        string $title = 'Hello World',
        ?string $metaTitle = null,
        ?string $metaDescription = null,
    ): ContentTranslation {
        return new ContentTranslation(
            id: 'trans-1',
            contentId: 'content-1',
            locale: 'en',
            title: $title,
            slugSegment: 'hello-world',
            path: 'hello-world',
            body: '<p>Body content</p>',
            excerpt: null,
            metaTitle: $metaTitle,
            metaDescription: $metaDescription,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: null,
            bodyPlaintext: 'Body content',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );
    }
}
