<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Http3;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Http3\FetchPriority;
use Pulsar\Http\Http3\PriorityHint;
use Pulsar\Http\Http3\PriorityHints;

#[CoversClass(PriorityHints::class)]
#[CoversClass(PriorityHint::class)]
#[CoversClass(FetchPriority::class)]
final class PriorityHintsTest extends TestCase
{
    #[Test]
    public function highPriorityGeneratesCorrectHeader(): void
    {
        $hints = new PriorityHints();
        $hints->high('/img/hero.webp', 'image');

        $headers = $hints->toLinkHeaders();

        self::assertCount(1, $headers);
        self::assertSame('</img/hero.webp>; rel=preload; as=image; fetchpriority=high', $headers[0]);
    }

    #[Test]
    public function lowPriorityGeneratesCorrectHeader(): void
    {
        $hints = new PriorityHints();
        $hints->low('/img/footer.png', 'image');

        $headers = $hints->toLinkHeaders();

        self::assertSame('</img/footer.png>; rel=preload; as=image; fetchpriority=low', $headers[0]);
    }

    #[Test]
    public function autoPriorityGeneratesCorrectHeader(): void
    {
        $hints = new PriorityHints();
        $hints->auto('/css/utils.css', 'style');

        $headers = $hints->toLinkHeaders();

        self::assertSame('</css/utils.css>; rel=preload; as=style; fetchpriority=auto', $headers[0]);
    }

    #[Test]
    public function multipleHints(): void
    {
        $hints = new PriorityHints();
        $hints->high('/img/hero.webp', 'image');
        $hints->low('/img/footer.png', 'image');
        $hints->auto('/js/analytics.js', 'script');

        self::assertSame(3, $hints->count());
        self::assertFalse($hints->isEmpty());
    }

    #[Test]
    public function emptyHints(): void
    {
        $hints = new PriorityHints();

        self::assertTrue($hints->isEmpty());
        self::assertSame(0, $hints->count());
        self::assertSame([], $hints->toLinkHeaders());
    }

    #[Test]
    public function toHtmlAttributesGeneratesCorrectMap(): void
    {
        $hints = new PriorityHints();
        $hints->high('/img/hero.webp', 'image');
        $hints->low('/img/footer.png', 'image');

        $attrs = $hints->toHtmlAttributes();

        self::assertSame('high', $attrs['/img/hero.webp']['fetchpriority']);
        self::assertSame('low', $attrs['/img/footer.png']['fetchpriority']);
    }

    #[Test]
    public function priorityForReturnsCorrectPriority(): void
    {
        $hints = new PriorityHints();
        $hints->high('/img/hero.webp', 'image');

        self::assertSame(FetchPriority::High, $hints->priorityFor('/img/hero.webp'));
        self::assertNull($hints->priorityFor('/nonexistent'));
    }

    #[Test]
    public function fetchPriorityEnumValues(): void
    {
        self::assertSame('high', FetchPriority::High->value);
        self::assertSame('low', FetchPriority::Low->value);
        self::assertSame('auto', FetchPriority::Auto->value);
    }

    #[Test]
    public function priorityHintToLinkHeaderValue(): void
    {
        $hint = new PriorityHint('/js/main.js', 'script', FetchPriority::High);

        self::assertSame('</js/main.js>; rel=preload; as=script; fetchpriority=high', $hint->toLinkHeaderValue());
    }

    #[Test]
    public function priorityHintToHtmlAttribute(): void
    {
        $hint = new PriorityHint('/img/logo.svg', 'image', FetchPriority::Low);

        self::assertSame('low', $hint->toHtmlAttribute());
    }

    #[Test]
    public function hintsReturnsAllPriorityHints(): void
    {
        $hints = new PriorityHints();
        $hints->high('/a', 'image');
        $hints->low('/b', 'script');

        $all = $hints->hints();

        self::assertCount(2, $all);
        self::assertContainsOnlyInstancesOf(PriorityHint::class, $all);
    }
}
