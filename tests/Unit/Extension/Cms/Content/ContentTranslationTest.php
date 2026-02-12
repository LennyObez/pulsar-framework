<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Exception\CmsException;
use ReflectionClass;

#[CoversClass(ContentTranslation::class)]
final class ContentTranslationTest extends TestCase
{
    private const string CONTENT_ID = '01912345-6789-7abc-8def-0123456789ab';

    #[Test]
    public function createWithValidSlug(): void
    {
        $translation = ContentTranslation::create(
            id: 'tr-001',
            contentId: self::CONTENT_ID,
            locale: 'en',
            title: 'Getting Started',
            slugSegment: 'getting-started',
            path: 'docs/getting-started',
            body: '<p>Welcome</p>',
        );

        self::assertSame('tr-001', $translation->id);
        self::assertSame(self::CONTENT_ID, $translation->contentId);
        self::assertSame('en', $translation->locale);
        self::assertSame('Getting Started', $translation->title);
        self::assertSame('getting-started', $translation->slugSegment);
        self::assertSame('docs/getting-started', $translation->path);
        self::assertSame('<p>Welcome</p>', $translation->body);
    }

    #[Test]
    public function createWithAllOptionalFields(): void
    {
        $translation = ContentTranslation::create(
            id: 'tr-002',
            contentId: self::CONTENT_ID,
            locale: 'en',
            title: 'Test',
            slugSegment: 'test',
            path: 'test',
            body: '<p>Body</p>',
            excerpt: 'Short summary',
            metaTitle: 'SEO Title',
            metaDescription: 'SEO Description',
            ogImageId: 'img-001',
            robots: 'noindex',
            structuredDataOverrides: ['@type' => 'Article'],
            readingTimeMinutes: 5,
            bodyPlaintext: 'Body',
            headingsText: 'Test',
            customFieldsText: 'field1 field2',
            taxonomyTermsText: 'category1 tag1',
        );

        self::assertSame('Short summary', $translation->excerpt);
        self::assertSame('SEO Title', $translation->metaTitle);
        self::assertSame('SEO Description', $translation->metaDescription);
        self::assertSame('img-001', $translation->ogImageId);
        self::assertSame('noindex', $translation->robots);
        self::assertSame(['@type' => 'Article'], $translation->structuredDataOverrides);
        self::assertSame(5, $translation->readingTimeMinutes);
        self::assertSame('Body', $translation->bodyPlaintext);
        self::assertSame('Test', $translation->headingsText);
        self::assertSame('field1 field2', $translation->customFieldsText);
        self::assertSame('category1 tag1', $translation->taxonomyTermsText);
    }

    #[Test]
    public function createWithInvalidSlugThrowsCmsException(): void
    {
        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('Invalid slug');

        ContentTranslation::create(
            id: 'tr-003',
            contentId: self::CONTENT_ID,
            locale: 'en',
            title: 'Test',
            slugSegment: 'Invalid Slug!',
            path: 'invalid-slug',
            body: '<p>Body</p>',
        );
    }

    // ── isValidSlug ──────────────────────────────────────────────────

    #[Test]
    #[DataProvider('validSlugProvider')]
    public function isValidSlugAcceptsValid(string $slug): void
    {
        self::assertTrue(ContentTranslation::isValidSlug($slug));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validSlugProvider(): iterable
    {
        yield 'simple word' => ['hello'];
        yield 'hyphenated' => ['hello-world'];
        yield 'numeric' => ['123'];
        yield 'mixed' => ['article-2024-01'];
        yield 'single char' => ['a'];
        yield 'at 200 chars' => [str_repeat('a', 200)];
    }

    #[Test]
    #[DataProvider('invalidSlugProvider')]
    public function isValidSlugRejectsInvalid(string $slug): void
    {
        self::assertFalse(ContentTranslation::isValidSlug($slug));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSlugProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Hello'];
        yield 'starts with hyphen' => ['-hello'];
        yield 'ends with hyphen' => ['hello-'];
        yield 'consecutive hyphens' => ['hello--world'];
        yield 'spaces' => ['hello world'];
        yield 'special chars' => ['hello!'];
        yield 'slashes' => ['hello/world'];
        yield 'over 200 chars' => [str_repeat('a', 201)];
        yield 'underscores' => ['hello_world'];
        yield 'dots' => ['hello.world'];
    }

    #[Test]
    public function isReadonlyClass(): void
    {
        $reflection = new ReflectionClass(ContentTranslation::class);
        self::assertTrue($reflection->isReadOnly());
    }
}
