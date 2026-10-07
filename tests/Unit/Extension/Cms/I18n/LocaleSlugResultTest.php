<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\I18n;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\Redirect;
use Pulsar\Extension\Cms\I18n\LocaleSlugResult;

#[CoversClass(LocaleSlugResult::class)]
final class LocaleSlugResultTest extends TestCase
{
    #[Test]
    public function resultWithTranslation(): void
    {
        $translation = new ContentTranslation(
            id: 'tr-01',
            contentId: 'c-01',
            locale: 'en',
            title: 'Test',
            slugSegment: 'test',
            path: 'test',
            body: '<p>Body</p>',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: null,
            bodyPlaintext: 'Body',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );

        $result = new LocaleSlugResult(
            locale: 'en',
            contentPath: '/test',
            translation: $translation,
            redirect: null,
        );

        self::assertTrue($result->hasTranslation());
        self::assertFalse($result->isRedirect());
        self::assertFalse($result->isFallback);
        self::assertSame('en', $result->locale);
    }

    #[Test]
    public function resultWithRedirect(): void
    {
        $now = new DateTimeImmutable();
        $redirect = new Redirect(
            id: 'rd-01',
            tenantId: null,
            fromPath: '/old-path',
            toPath: '/new-path',
            statusCode: 301,
            locale: null,
            hits: 0,
            lastHitAt: null,
            createdAt: $now,
            createdBy: 'system',
            reason: 'Slug changed',
        );

        $result = new LocaleSlugResult(
            locale: 'en',
            contentPath: '/old-path',
            translation: null,
            redirect: $redirect,
        );

        self::assertFalse($result->hasTranslation());
        self::assertTrue($result->isRedirect());
    }

    #[Test]
    public function resultWithFallback(): void
    {
        $result = new LocaleSlugResult(
            locale: 'fr',
            contentPath: '/test',
            translation: null,
            redirect: null,
            isFallback: true,
        );

        self::assertTrue($result->isFallback);
        self::assertFalse($result->hasTranslation());
        self::assertFalse($result->isRedirect());
    }
}
