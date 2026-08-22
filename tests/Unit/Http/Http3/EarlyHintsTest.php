<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Http3;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Http3\EarlyHints;
use Pulsar\Http\Http3\LinkHint;

#[CoversClass(EarlyHints::class)]
#[CoversClass(LinkHint::class)]
final class EarlyHintsTest extends TestCase
{
    #[Test]
    public function preloadStylesheetGeneratesCorrectHeader(): void
    {
        $hints = new EarlyHints();
        $hints->preloadStylesheet('/css/app.css');

        $headers = $hints->toLinkHeaders();

        self::assertCount(1, $headers);
        self::assertSame('</css/app.css>; rel=preload; as=style', $headers[0]);
    }

    #[Test]
    public function preloadScriptGeneratesCorrectHeader(): void
    {
        $hints = new EarlyHints();
        $hints->preloadScript('/js/app.js');

        $headers = $hints->toLinkHeaders();

        self::assertSame('</js/app.js>; rel=preload; as=script', $headers[0]);
    }

    #[Test]
    public function preloadFontAlwaysIncludesCrossorigin(): void
    {
        $hints = new EarlyHints();
        $hints->preloadFont('/fonts/inter.woff2');

        $headers = $hints->toLinkHeaders();

        self::assertStringContainsString('as=font', $headers[0]);
        self::assertStringContainsString('crossorigin', $headers[0]);
    }

    #[Test]
    public function preloadImageWithoutCrossorigin(): void
    {
        $hints = new EarlyHints();
        $hints->preloadImage('/img/hero.webp');

        $headers = $hints->toLinkHeaders();

        self::assertSame('</img/hero.webp>; rel=preload; as=image', $headers[0]);
        self::assertStringNotContainsString('crossorigin', $headers[0]);
    }

    #[Test]
    public function preloadImageWithCrossorigin(): void
    {
        $hints = new EarlyHints();
        $hints->preloadImage('https://cdn.example.com/img.jpg', crossOrigin: true);

        $headers = $hints->toLinkHeaders();

        self::assertStringContainsString('crossorigin', $headers[0]);
    }

    #[Test]
    public function preloadFetchDefaultsCrossoriginTrue(): void
    {
        $hints = new EarlyHints();
        $hints->preloadFetch('/api/config');

        $headers = $hints->toLinkHeaders();

        self::assertStringContainsString('as=fetch', $headers[0]);
        self::assertStringContainsString('crossorigin', $headers[0]);
    }

    #[Test]
    public function preconnectGeneratesCorrectHeader(): void
    {
        $hints = new EarlyHints();
        $hints->preconnect('https://cdn.example.com');

        $headers = $hints->toLinkHeaders();

        self::assertSame('<https://cdn.example.com>; rel=preconnect; crossorigin', $headers[0]);
    }

    #[Test]
    public function dnsPrefetchGeneratesCorrectHeader(): void
    {
        $hints = new EarlyHints();
        $hints->dnsPrefetch('https://analytics.example.com');

        $headers = $hints->toLinkHeaders();

        self::assertSame('<https://analytics.example.com>; rel=dns-prefetch', $headers[0]);
    }

    #[Test]
    public function multipleHintsCombineCorrectly(): void
    {
        $hints = new EarlyHints();
        $hints->preloadStylesheet('/css/app.css');
        $hints->preloadScript('/js/app.js');
        $hints->preloadFont('/fonts/inter.woff2');

        self::assertSame(3, $hints->count());
        self::assertFalse($hints->isEmpty());

        $combined = $hints->toCombinedHeader();

        self::assertStringContainsString('</css/app.css>', $combined);
        self::assertStringContainsString('</js/app.js>', $combined);
        self::assertStringContainsString('</fonts/inter.woff2>', $combined);
    }

    #[Test]
    public function emptyHints(): void
    {
        $hints = new EarlyHints();

        self::assertTrue($hints->isEmpty());
        self::assertSame(0, $hints->count());
        self::assertSame([], $hints->toLinkHeaders());
        self::assertSame('', $hints->toCombinedHeader());
    }

    #[Test]
    public function addHintSupportsCustomRelAndAs(): void
    {
        $hints = new EarlyHints();
        $hints->addHint('/data.json', 'modulepreload', 'script');

        $headers = $hints->toLinkHeaders();

        self::assertSame('</data.json>; rel=modulepreload; as=script', $headers[0]);
    }

    #[Test]
    public function hintsReturnsAllLinkHints(): void
    {
        $hints = new EarlyHints();
        $hints->preloadStylesheet('/a.css');
        $hints->preloadScript('/b.js');

        $all = $hints->hints();

        self::assertCount(2, $all);
        self::assertContainsOnlyInstancesOf(LinkHint::class, $all);
    }

    #[Test]
    public function linkHintWithoutAsOmitsAsAttribute(): void
    {
        $hint = new LinkHint(href: 'https://cdn.example.com', rel: 'preconnect');

        self::assertSame('<https://cdn.example.com>; rel=preconnect', $hint->toHeaderValue());
    }

    #[Test]
    public function linkHintWithCrossoriginAppendsAttribute(): void
    {
        $hint = new LinkHint(href: '/font.woff2', rel: 'preload', as: 'font', crossOrigin: true);

        self::assertSame('</font.woff2>; rel=preload; as=font; crossorigin', $hint->toHeaderValue());
    }
}
