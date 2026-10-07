<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Exception\CmsException;

use function str_repeat;

#[CoversClass(ContentTranslation::class)]
final class ContentTranslationSlugTest extends TestCase
{
    #[Test]
    public function emptyStringIsValidForHomepage(): void
    {
        // Arrange / Act
        $result = ContentTranslation::isValidSlug('');

        // Assert
        self::assertTrue($result, 'Empty slug must be valid (homepage root)');
    }

    #[Test]
    public function standardLowercaseSlugIsValid(): void
    {
        self::assertTrue(ContentTranslation::isValidSlug('valid-slug'));
    }

    #[Test]
    public function singleCharacterSlugIsValid(): void
    {
        self::assertTrue(ContentTranslation::isValidSlug('a'));
    }

    #[Test]
    public function numericSlugIsValid(): void
    {
        self::assertTrue(ContentTranslation::isValidSlug('42'));
    }

    #[Test]
    public function slugAtMaxLength200IsValid(): void
    {
        $slug = str_repeat('a', 200);

        self::assertTrue(ContentTranslation::isValidSlug($slug));
    }

    #[Test]
    public function slugExceeding200CharsIsInvalid(): void
    {
        $slug = str_repeat('a', 201);

        self::assertFalse(ContentTranslation::isValidSlug($slug));
    }

    #[Test]
    #[DataProvider('invalidSlugProvider')]
    public function invalidSlugsAreRejected(string $slug, string $reason): void
    {
        self::assertFalse(
            ContentTranslation::isValidSlug($slug),
            "Slug should be invalid: {$reason}",
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidSlugProvider(): iterable
    {
        yield 'uppercase letters' => ['INVALID', 'uppercase not allowed'];
        yield 'mixed case' => ['Some-Page', 'uppercase not allowed'];
        yield 'consecutive hyphens' => ['some--slug', 'double hyphens not allowed'];
        yield 'starts with hyphen' => ['-leading', 'must start with alphanumeric'];
        yield 'ends with hyphen' => ['trailing-', 'must end with alphanumeric'];
        yield 'contains space' => ['has space', 'spaces not allowed'];
        yield 'contains underscore' => ['has_underscore', 'underscores not allowed'];
        yield 'special characters' => ['hello@world', 'special chars not allowed'];
    }

    #[Test]
    public function createAcceptsEmptySlugForHomepage(): void
    {
        // Arrange / Act
        $translation = ContentTranslation::create(
            id: '01900000-0000-7000-8000-000000000001',
            contentId: '01900000-0000-7000-8000-000000000002',
            locale: 'en',
            title: 'Homepage',
            slugSegment: '',
            path: '',
            body: '<p>Welcome</p>',
        );

        // Assert
        self::assertSame('', $translation->slugSegment);
    }

    #[Test]
    public function createThrowsForInvalidSlug(): void
    {
        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('Invalid slug format');

        ContentTranslation::create(
            id: '01900000-0000-7000-8000-000000000001',
            contentId: '01900000-0000-7000-8000-000000000002',
            locale: 'en',
            title: 'Bad Page',
            slugSegment: 'INVALID',
            path: 'INVALID',
            body: '<p>Test</p>',
        );
    }
}
