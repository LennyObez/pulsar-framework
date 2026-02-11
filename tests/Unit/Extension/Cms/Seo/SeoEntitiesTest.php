<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Seo;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Seo\Event\LinkHealthCheckCompleted;
use Pulsar\Extension\Cms\Seo\Event\SitemapRegenerated;
use Pulsar\Extension\Cms\Seo\JsonLdCollection;
use Pulsar\Extension\Cms\Seo\LinkHealthCheck;
use Pulsar\Extension\Cms\Seo\MetaTagCollection;

#[CoversClass(JsonLdCollection::class)]
#[CoversClass(LinkHealthCheck::class)]
#[CoversClass(LinkHealthCheckCompleted::class)]
#[CoversClass(MetaTagCollection::class)]
#[CoversClass(SitemapRegenerated::class)]
final class SeoEntitiesTest extends TestCase
{
    // -- MetaTagCollection ----------------------------------------------------

    #[Test]
    public function metaTagCollectionEmptyToHtml(): void
    {
        $collection = new MetaTagCollection();

        self::assertSame('', $collection->toHtml());
    }

    #[Test]
    public function metaTagCollectionFullToHtml(): void
    {
        $collection = new MetaTagCollection(
            title: 'PHP Security Guide',
            description: 'A comprehensive guide to securing PHP applications',
            canonical: 'https://example.com/guides/php-security',
            robots: 'index, follow',
            ogTags: ['og:title' => 'PHP Security Guide', 'og:type' => 'article'],
            twitterCards: ['twitter:card' => 'summary_large_image'],
            hreflangLinks: ['en' => 'https://example.com/en/guides/php-security', 'fr' => 'https://example.com/fr/guides/php-security'],
        );

        $html = $collection->toHtml();

        self::assertStringContainsString('<title>PHP Security Guide</title>', $html);
        self::assertStringContainsString('<meta name="description" content="A comprehensive guide to securing PHP applications">', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://example.com/guides/php-security">', $html);
        self::assertStringContainsString('<meta name="robots" content="index, follow">', $html);
        self::assertStringContainsString('<meta property="og:title" content="PHP Security Guide">', $html);
        self::assertStringContainsString('<meta property="og:type" content="article">', $html);
        self::assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
        self::assertStringContainsString('hreflang="en"', $html);
        self::assertStringContainsString('hreflang="fr"', $html);
    }

    #[Test]
    public function metaTagCollectionXssEscaping(): void
    {
        $collection = new MetaTagCollection(
            title: '<script>alert("xss")</script>',
            description: '" onmouseover="alert(1)"',
        );

        $html = $collection->toHtml();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        // The quotes in the description are escaped, preventing attribute injection
        self::assertStringContainsString('&quot; onmouseover=&quot;alert(1)&quot;', $html);
    }

    // -- JsonLdCollection -----------------------------------------------------

    #[Test]
    public function jsonLdCollectionEmptyReturnsEmpty(): void
    {
        $collection = new JsonLdCollection();

        self::assertSame('', $collection->toScript());
    }

    #[Test]
    public function jsonLdCollectionSingleItemNoGraph(): void
    {
        $collection = new JsonLdCollection([
            ['@type' => 'Article', 'name' => 'PHP Patterns'],
        ]);

        $script = $collection->toScript();

        self::assertStringContainsString('<script type="application/ld+json">', $script);
        self::assertStringContainsString('"@type":"Article"', $script);
        self::assertStringNotContainsString('@graph', $script);
    }

    #[Test]
    public function jsonLdCollectionMultipleItemsUsesGraph(): void
    {
        $collection = new JsonLdCollection([
            ['@type' => 'Article', 'name' => 'PHP Patterns'],
            ['@type' => 'BreadcrumbList', 'itemListElement' => []],
        ]);

        $script = $collection->toScript();

        self::assertStringContainsString('@graph', $script);
        self::assertStringContainsString('"@type":"Article"', $script);
        self::assertStringContainsString('"@type":"BreadcrumbList"', $script);
    }

    // -- LinkHealthCheck ------------------------------------------------------

    #[Test]
    public function linkHealthCheckBrokenLink(): void
    {
        $now = new DateTimeImmutable('2025-03-07T10:00:00+00:00');

        $check = new LinkHealthCheck(
            id: '0194d4e0-1111-7000-2222-000000000001',
            tenantId: 'tenant-01',
            sourceContentId: '0194d4e0-3333-7000-4444-000000000001',
            sourceLocale: 'en',
            targetUrl: 'https://example.com/broken-page',
            isBroken: true,
            isRedirected: false,
            httpStatusCode: 404,
            lastCheckedAt: $now,
            createdAt: $now,
        );

        self::assertTrue($check->isBroken);
        self::assertFalse($check->isRedirected);
        self::assertSame(404, $check->httpStatusCode);
        self::assertSame('tenant-01', $check->tenantId);
        self::assertSame('en', $check->sourceLocale);
    }

    #[Test]
    public function linkHealthCheckRedirectedLink(): void
    {
        $now = new DateTimeImmutable();

        $check = new LinkHealthCheck(
            id: '0194d4e0-1111-7000-2222-000000000002',
            tenantId: null,
            sourceContentId: '0194d4e0-3333-7000-4444-000000000002',
            sourceLocale: 'de',
            targetUrl: 'https://old.example.com/page',
            isBroken: false,
            isRedirected: true,
            httpStatusCode: 301,
            lastCheckedAt: $now,
            createdAt: $now,
        );

        self::assertFalse($check->isBroken);
        self::assertTrue($check->isRedirected);
        self::assertSame(301, $check->httpStatusCode);
        self::assertNull($check->tenantId);
    }

    #[Test]
    public function linkHealthCheckUnreachable(): void
    {
        $now = new DateTimeImmutable();

        $check = new LinkHealthCheck(
            id: '0194d4e0-1111-7000-2222-000000000003',
            tenantId: null,
            sourceContentId: '0194d4e0-3333-7000-4444-000000000003',
            sourceLocale: 'en',
            targetUrl: 'https://unreachable.example.com',
            isBroken: true,
            isRedirected: false,
            httpStatusCode: null,
            lastCheckedAt: $now,
            createdAt: $now,
        );

        self::assertTrue($check->isBroken);
        self::assertNull($check->httpStatusCode);
    }

    // -- Seo Events -----------------------------------------------------------

    #[Test]
    public function linkHealthCheckCompletedEvent(): void
    {
        $event = new LinkHealthCheckCompleted(
            totalChecked: 500,
            brokenCount: 12,
        );

        self::assertSame(500, $event->totalChecked);
        self::assertSame(12, $event->brokenCount);
    }

    #[Test]
    public function sitemapRegeneratedEvent(): void
    {
        $event = new SitemapRegenerated(
            pageCount: 1500,
            localeCount: 4,
        );

        self::assertSame(1500, $event->pageCount);
        self::assertSame(4, $event->localeCount);
    }
}
