<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Internal\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\Tools\ImportFieldResolverTrait;

#[CoversClass(ImportFieldResolverTrait::class)]
final class ImportFieldResolverTraitTest extends TestCase
{
    private object $resolver;

    protected function setUp(): void
    {
        $this->resolver = new class {
            use ImportFieldResolverTrait {
                resolveSlug as public;
                resolveAuthorId as public;
                resolveTranslationSlugSegment as public;
            }
        };
    }

    // -- resolveSlug --

    #[Test]
    public function resolveSlugPrefersSlugField(): void
    {
        $result = $this->resolver->resolveSlug(['slug' => 'about', 'slug_segment' => 'about-us']);

        self::assertSame('about', $result);
    }

    #[Test]
    public function resolveSlugFallsBackToSlugSegment(): void
    {
        $result = $this->resolver->resolveSlug(['slug_segment' => 'about-us']);

        self::assertSame('about-us', $result);
    }

    #[Test]
    public function resolveSlugFallsBackToImportId(): void
    {
        $result = $this->resolver->resolveSlug([], 'page:home');

        self::assertSame('page:home', $result);
    }

    #[Test]
    public function resolveSlugReturnsNullWhenAllAbsent(): void
    {
        $result = $this->resolver->resolveSlug([]);

        self::assertNull($result);
    }

    #[Test]
    public function resolveSlugAcceptsEmptyStringAsValidHomepageSlug(): void
    {
        $result = $this->resolver->resolveSlug(['slug' => '']);

        self::assertSame('', $result);
    }

    #[Test]
    public function resolveSlugIgnoresNonStringValues(): void
    {
        $result = $this->resolver->resolveSlug(['slug' => 42, 'slug_segment' => true]);

        self::assertNull($result);
    }

    #[Test]
    public function resolveSlugWithNullFallbackAndNoFields(): void
    {
        $result = $this->resolver->resolveSlug([], null);

        self::assertNull($result);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?string, ?string}>
     */
    public static function slugDataProvider(): iterable
    {
        yield 'slug only' => [['slug' => 'products'], null, 'products'];
        yield 'slug_segment only' => [['slug_segment' => 'shop'], null, 'shop'];
        yield 'both present, slug wins' => [['slug' => 'a', 'slug_segment' => 'b'], null, 'a'];
        yield 'neither, fallback used' => [[], 'content:faq', 'content:faq'];
        yield 'neither, no fallback' => [[], null, null];
        yield 'empty slug is valid' => [['slug' => ''], null, ''];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('slugDataProvider')]
    public function resolveSlugWithDataProvider(array $data, ?string $fallback, ?string $expected): void
    {
        self::assertSame($expected, $this->resolver->resolveSlug($data, $fallback));
    }

    // -- resolveAuthorId --

    #[Test]
    public function resolveAuthorIdPrefersAuthorIdField(): void
    {
        $result = $this->resolver->resolveAuthorId(['author_id' => 'user-1', 'author' => 'user-2']);

        self::assertSame('user-1', $result);
    }

    #[Test]
    public function resolveAuthorIdFallsBackToAuthorField(): void
    {
        $result = $this->resolver->resolveAuthorId(['author' => 'user-2']);

        self::assertSame('user-2', $result);
    }

    #[Test]
    public function resolveAuthorIdDefaultsToSystem(): void
    {
        $result = $this->resolver->resolveAuthorId([]);

        self::assertSame('system', $result);
    }

    #[Test]
    public function resolveAuthorIdIgnoresNonStringValues(): void
    {
        $result = $this->resolver->resolveAuthorId(['author_id' => 123, 'author' => false]);

        self::assertSame('system', $result);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function authorIdDataProvider(): iterable
    {
        yield 'author_id present' => [['author_id' => 'uuid-1'], 'uuid-1'];
        yield 'author present' => [['author' => 'uuid-2'], 'uuid-2'];
        yield 'both, author_id wins' => [['author_id' => 'a', 'author' => 'b'], 'a'];
        yield 'neither present' => [[], 'system'];
        yield 'null values' => [['author_id' => null, 'author' => null], 'system'];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('authorIdDataProvider')]
    public function resolveAuthorIdWithDataProvider(array $data, string $expected): void
    {
        self::assertSame($expected, $this->resolver->resolveAuthorId($data));
    }

    // -- resolveTranslationSlugSegment --

    #[Test]
    public function resolveTranslationSlugSegmentPrefersSlugSegmentField(): void
    {
        $result = $this->resolver->resolveTranslationSlugSegment(
            ['slug_segment' => 'about-us', 'slug' => 'about'],
            'fallback',
        );

        self::assertSame('about-us', $result);
    }

    #[Test]
    public function resolveTranslationSlugSegmentFallsBackToSlug(): void
    {
        $result = $this->resolver->resolveTranslationSlugSegment(
            ['slug' => 'about'],
            'fallback',
        );

        self::assertSame('about', $result);
    }

    #[Test]
    public function resolveTranslationSlugSegmentFallsBackToRootSlug(): void
    {
        $result = $this->resolver->resolveTranslationSlugSegment(
            [],
            'root-slug',
        );

        self::assertSame('root-slug', $result);
    }

    #[Test]
    public function resolveTranslationSlugSegmentIgnoresNonStringValues(): void
    {
        $result = $this->resolver->resolveTranslationSlugSegment(
            ['slug_segment' => 42, 'slug' => null],
            'default',
        );

        self::assertSame('default', $result);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function translationSlugDataProvider(): iterable
    {
        yield 'slug_segment wins' => [['slug_segment' => 'a', 'slug' => 'b'], 'c', 'a'];
        yield 'slug fallback' => [['slug' => 'b'], 'c', 'b'];
        yield 'root fallback' => [[], 'c', 'c'];
        yield 'empty segment is valid' => [['slug_segment' => ''], 'c', ''];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('translationSlugDataProvider')]
    public function resolveTranslationSlugSegmentWithDataProvider(
        array $data,
        string $rootSlug,
        string $expected,
    ): void {
        self::assertSame($expected, $this->resolver->resolveTranslationSlugSegment($data, $rootSlug));
    }
}
