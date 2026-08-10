<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Locale;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Locale\UrlPrefixExtractor;

#[CoversClass(UrlPrefixExtractor::class)]
final class UrlPrefixExtractorTest extends TestCase
{
    private UrlPrefixExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new UrlPrefixExtractor();
    }

    // ── extract() ───────────────────────────────────────────────────────

    #[Test]
    public function extractReturnsTwoCharLocaleFromPath(): void
    {
        self::assertSame('fr', $this->extractor->extract('/fr/docs/intro', ['en', 'fr', 'de']));
    }

    #[Test]
    public function extractReturnsRegionalLocaleFromPath(): void
    {
        self::assertSame('fr-CA', $this->extractor->extract('/fr-CA/about', ['en', 'fr-CA', 'de']));
    }

    #[Test]
    public function extractReturnsNullForUnsupportedLocale(): void
    {
        self::assertNull($this->extractor->extract('/it/docs', ['en', 'fr']));
    }

    #[Test]
    public function extractAcceptsConfiguredLocalesOfAnyShape(): void
    {
        // A configured locale resolves whatever its shape — 3-letter ISO
        // 639-2/3 (fil, gsw) or underscore region (fr_CA) — instead of being
        // pre-rejected by a fixed 2-letter / xx-YY pattern.
        self::assertSame('fil', $this->extractor->extract('/fil/about', ['en', 'fil']));
        self::assertSame('gsw', $this->extractor->extract('/gsw/docs', ['en', 'gsw']));
        self::assertSame('fr_CA', $this->extractor->extract('/fr_CA/about', ['en', 'fr_CA']));
    }

    #[Test]
    public function extractReturnsNullForEmptyPath(): void
    {
        self::assertNull($this->extractor->extract('/', ['en', 'fr']));
    }

    #[Test]
    public function extractReturnsLocaleFromPathWithoutTrailingContent(): void
    {
        self::assertSame('de', $this->extractor->extract('/de', ['en', 'fr', 'de']));
    }

    #[Test]
    public function extractReturnsNullForPathTraversal(): void
    {
        self::assertNull($this->extractor->extract('/../fr/docs', ['en', 'fr']));
    }

    #[Test]
    public function extractReturnsNullForDotDotSegmentInPath(): void
    {
        self::assertNull($this->extractor->extract('/fr/../etc/passwd', ['fr']));
    }

    #[Test]
    public function extractReturnsNullForTrailingDotDot(): void
    {
        self::assertNull($this->extractor->extract('/fr/..', ['fr']));
    }

    #[Test]
    public function extractReturnsNullForLeadingDotDotRelative(): void
    {
        self::assertNull($this->extractor->extract('../fr/docs', ['fr']));
    }

    #[Test]
    public function extractReturnsNullForBareDotDotPath(): void
    {
        self::assertNull($this->extractor->extract('/..', ['en', 'fr']));
    }

    #[Test]
    public function extractDoesNotFalsePositiveOnShortPaths(): void
    {
        // Paths shorter than 3 chars can't contain traversal
        self::assertNull($this->extractor->extract('/', ['en']));
        self::assertNull($this->extractor->extract('', ['en']));
    }

    #[Test]
    public function extractAllowsDoubleDotInSegmentName(): void
    {
        self::assertSame('fr', $this->extractor->extract('/fr/file..v2', ['en', 'fr']));
    }

    #[Test]
    public function extractAllowsDoubleDotInFileName(): void
    {
        self::assertSame('en', $this->extractor->extract('/en/release..notes', ['en', 'fr']));
    }

    #[Test]
    public function extractReturnsNullForNonAlphaSegment(): void
    {
        self::assertNull($this->extractor->extract('/123/page', ['en', 'fr']));
    }

    #[Test]
    public function extractReturnsNullForUnconfiguredLongSegment(): void
    {
        // A long path segment that is not a configured locale is not a prefix.
        // Rejection follows from the segment being absent from the supported
        // list, never from a shape heuristic: a 2-letter / xx-YY pattern would
        // also reject legitimate 3-letter and underscore-region locales.
        self::assertNull($this->extractor->extract('/abcdef/page', ['en', 'fr']));
    }

    // ── stripPrefix() ───────────────────────────────────────────────────

    #[Test]
    public function stripPrefixRemovesLocaleFromPath(): void
    {
        self::assertSame('/docs/intro', $this->extractor->stripPrefix('/fr/docs/intro', 'fr'));
    }

    #[Test]
    public function stripPrefixReturnsSlashForLocaleOnly(): void
    {
        self::assertSame('/', $this->extractor->stripPrefix('/fr', 'fr'));
    }

    #[Test]
    public function stripPrefixReturnsPathAsIsWhenNoPrefix(): void
    {
        self::assertSame('/docs/intro', $this->extractor->stripPrefix('/docs/intro', 'de'));
    }

    #[Test]
    public function stripPrefixCollapsesProtocolRelativeDoubleSlash(): void
    {
        self::assertSame('/evil.com', $this->extractor->stripPrefix('/en//evil.com', 'en'));
    }

    #[Test]
    public function stripPrefixCollapsesMultipleLeadingSlashes(): void
    {
        self::assertSame('/evil.com/path', $this->extractor->stripPrefix('/fr///evil.com/path', 'fr'));
    }

    // ── buildPath() ─────────────────────────────────────────────────────

    #[Test]
    public function buildPathAddsLocalePrefix(): void
    {
        self::assertSame('/fr/docs/intro', $this->extractor->buildPath('/docs/intro', 'fr', 'en', false));
    }

    #[Test]
    public function buildPathOmitsDefaultLocaleWhenNotInUrl(): void
    {
        self::assertSame('/docs/intro', $this->extractor->buildPath('/docs/intro', 'en', 'en', false));
    }

    #[Test]
    public function buildPathIncludesDefaultLocaleWhenConfigured(): void
    {
        self::assertSame('/en/docs/intro', $this->extractor->buildPath('/docs/intro', 'en', 'en', true));
    }

    #[Test]
    public function buildPathHandlesEmptyContentPath(): void
    {
        self::assertSame('/fr', $this->extractor->buildPath('/', 'fr', 'en', false));
    }

    #[Test]
    public function buildPathReturnsSlashForDefaultLocaleEmptyPath(): void
    {
        self::assertSame('/', $this->extractor->buildPath('/', 'en', 'en', false));
    }

    #[Test]
    public function buildPathNormalizesPathWithoutLeadingSlash(): void
    {
        self::assertSame('/fr/docs', $this->extractor->buildPath('docs', 'fr', 'en', false));
    }

    #[Test]
    public function buildPathNormalizesNestedPathWithoutLeadingSlash(): void
    {
        self::assertSame('/de/docs/intro', $this->extractor->buildPath('docs/intro', 'de', 'en', false));
    }

    #[Test]
    public function buildPathNormalizesDefaultLocalePathWithoutLeadingSlash(): void
    {
        self::assertSame('/docs', $this->extractor->buildPath('docs', 'en', 'en', false));
    }

    #[Test]
    public function buildPathNormalizesEmptyStringToSlash(): void
    {
        self::assertSame('/', $this->extractor->buildPath('', 'en', 'en', false));
    }
}
