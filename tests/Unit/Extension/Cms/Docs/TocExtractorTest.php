<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Docs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Docs\TocExtractor;

#[CoversClass(TocExtractor::class)]
final class TocExtractorTest extends TestCase
{
    #[Test]
    public function extractH2AndH3Headings(): void
    {
        $html = <<<'HTML'
            <h2 id="intro">Introduction</h2>
            <p>Some content</p>
            <h3 id="sub-section">Sub Section</h3>
            <p>More content</p>
            <h2 id="conclusion">Conclusion</h2>
            HTML;

        $entries = TocExtractor::extract($html);

        self::assertCount(3, $entries);

        self::assertSame(2, $entries[0]['level']);
        self::assertSame('intro', $entries[0]['id']);
        self::assertSame('Introduction', $entries[0]['text']);

        self::assertSame(3, $entries[1]['level']);
        self::assertSame('sub-section', $entries[1]['id']);
        self::assertSame('Sub Section', $entries[1]['text']);

        self::assertSame(2, $entries[2]['level']);
        self::assertSame('conclusion', $entries[2]['id']);
        self::assertSame('Conclusion', $entries[2]['text']);
    }

    #[Test]
    public function extractReturnsEmptyArrayForNoHeadings(): void
    {
        $html = '<p>Just a paragraph</p><div>And a div</div>';

        self::assertSame([], TocExtractor::extract($html));
    }

    #[Test]
    public function extractPreservesHeadingOrder(): void
    {
        $html = '<h3>Third Level First</h3><h2>Second Level After</h2>';

        $entries = TocExtractor::extract($html);

        self::assertCount(2, $entries);
        self::assertSame('Third Level First', $entries[0]['text']);
        self::assertSame('Second Level After', $entries[1]['text']);
    }

    #[Test]
    public function extractGeneratesSlugifiedIdWhenMissing(): void
    {
        $html = '<h2>Getting Started Guide</h2>';

        $entries = TocExtractor::extract($html);

        self::assertCount(1, $entries);
        self::assertSame('getting-started-guide', $entries[0]['id']);
        self::assertSame('Getting Started Guide', $entries[0]['text']);
    }

    #[Test]
    public function extractStripsInlineHtmlFromText(): void
    {
        $html = '<h2 id="bold-heading"><strong>Bold</strong> Heading</h2>';

        $entries = TocExtractor::extract($html);

        self::assertCount(1, $entries);
        self::assertSame('Bold Heading', $entries[0]['text']);
        self::assertSame('bold-heading', $entries[0]['id']);
    }

    #[Test]
    public function extractIgnoresH1AndH4Through6(): void
    {
        $html = <<<'HTML'
            <h1>Title</h1>
            <h2>Included</h2>
            <h4>Not Included</h4>
            <h5>Not Included Either</h5>
            <h6>Also Not</h6>
            HTML;

        $entries = TocExtractor::extract($html);

        self::assertCount(1, $entries);
        self::assertSame('Included', $entries[0]['text']);
    }

    #[Test]
    public function extractHandlesSpecialCharactersInSlug(): void
    {
        $html = '<h2>API & Configuration (v2.0)</h2>';

        $entries = TocExtractor::extract($html);

        self::assertCount(1, $entries);
        // Special chars become hyphens, consecutive hyphens collapsed
        $slug = $entries[0]['id'];
        self::assertMatchesRegularExpression('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $slug);
    }
}
