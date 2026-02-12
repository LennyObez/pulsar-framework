<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Content;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Exception\CmsException;

final class ContentTranslationTest extends TestCase
{
    #[Test]
    public function is_valid_slug_accepts_valid_slugs(): void
    {
        self::assertTrue(ContentTranslation::isValidSlug('hello'));
        self::assertTrue(ContentTranslation::isValidSlug('hello-world'));
        self::assertTrue(ContentTranslation::isValidSlug('a1b2c3'));
        self::assertTrue(ContentTranslation::isValidSlug('my-cool-post-123'));
    }

    #[Test]
    public function is_valid_slug_rejects_leading_hyphen(): void
    {
        self::assertFalse(ContentTranslation::isValidSlug('-hello'));
    }

    #[Test]
    public function is_valid_slug_rejects_trailing_hyphen(): void
    {
        self::assertFalse(ContentTranslation::isValidSlug('hello-'));
    }

    #[Test]
    public function is_valid_slug_rejects_consecutive_hyphens(): void
    {
        self::assertFalse(ContentTranslation::isValidSlug('hello--world'));
    }

    #[Test]
    public function is_valid_slug_rejects_uppercase(): void
    {
        self::assertFalse(ContentTranslation::isValidSlug('Hello'));
    }

    #[Test]
    public function is_valid_slug_rejects_empty_string(): void
    {
        self::assertFalse(ContentTranslation::isValidSlug(''));
    }

    #[Test]
    public function is_valid_slug_rejects_too_long(): void
    {
        $slug = str_repeat('a', 201);

        self::assertFalse(ContentTranslation::isValidSlug($slug));
    }

    #[Test]
    public function is_valid_slug_accepts_max_length(): void
    {
        $slug = str_repeat('a', 200);

        self::assertTrue(ContentTranslation::isValidSlug($slug));
    }

    #[Test]
    public function create_with_valid_slug_succeeds(): void
    {
        $translation = ContentTranslation::create(
            id: 'tr-1',
            contentId: 'c-1',
            locale: 'en',
            title: 'Hello World',
            slugSegment: 'hello-world',
            path: 'hello-world',
            body: '<p>Hello</p>',
        );

        self::assertSame('tr-1', $translation->id);
        self::assertSame('hello-world', $translation->slugSegment);
    }

    #[Test]
    public function create_with_invalid_slug_throws(): void
    {
        $this->expectException(CmsException::class);

        ContentTranslation::create(
            id: 'tr-1',
            contentId: 'c-1',
            locale: 'en',
            title: 'Test',
            slugSegment: 'INVALID SLUG',
            path: 'invalid-slug',
            body: '<p>Test</p>',
        );
    }
}
