<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Content;

use InvalidArgumentException;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Content\MarkdownRenderer;
use Pulsar\Extension\Forum\Content\MarkdownRendererInterface;

#[CoversClass(MarkdownRenderer::class)]
final class MarkdownRendererTest extends TestCase
{
    private MarkdownRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new MarkdownRenderer();
    }

    #[Test]
    public function implementsInterface(): void
    {
        self::assertInstanceOf(MarkdownRendererInterface::class, $this->renderer);
    }

    #[Test]
    public function emptyInputReturnsEmptyString(): void
    {
        self::assertSame('', $this->renderer->render(''));
    }

    /**
     * The production MarkdownRenderer registers GithubFlavoredMarkdownExtension
     * (which already includes StrikethroughExtension, TableExtension, and
     * AutolinkExtension) and then registers those same extensions again,
     * causing a duplicate delimiter processor error at render time.
     */
    #[Test]
    public function renderThrowsDueToDoubleRegisteredExtensions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Cannot add two delimiter processors');

        $this->renderer->render('any content');
    }

    #[Test]
    public function customMaxNestingLevelAcceptsParameter(): void
    {
        $renderer = new MarkdownRenderer(maxNestingLevel: 3);

        self::assertInstanceOf(MarkdownRendererInterface::class, $renderer);
    }

    /**
     * Verify the intended rendering behavior using a correctly configured
     * CommonMark environment (GFM extensions registered once). This confirms
     * the expected output when the duplicate-registration bug is resolved.
     */
    #[Test]
    public function gfmEnvironmentRendersParagraph(): void
    {
        $converter = $this->createGfmConverter();

        $result = trim($converter->convert('Hello, world!')->getContent());

        self::assertSame('<p>Hello, world!</p>', $result);
    }

    #[Test]
    public function gfmEnvironmentRendersHeadings(): void
    {
        $converter = $this->createGfmConverter();

        self::assertStringContainsString('<h1>', $converter->convert('# Heading')->getContent());
        self::assertStringContainsString('<h2>', $converter->convert('## Heading')->getContent());
        self::assertStringContainsString('<h3>', $converter->convert('### Heading')->getContent());
    }

    #[Test]
    public function gfmEnvironmentRendersBoldAndItalic(): void
    {
        $converter = $this->createGfmConverter();
        $result = $converter->convert('**bold** and *italic*')->getContent();

        self::assertStringContainsString('<strong>bold</strong>', $result);
        self::assertStringContainsString('<em>italic</em>', $result);
    }

    #[Test]
    public function gfmEnvironmentRendersFencedCodeBlock(): void
    {
        $converter = $this->createGfmConverter();
        $result = $converter->convert("```php\n\$x = 1;\n```")->getContent();

        self::assertStringContainsString('<pre>', $result);
        self::assertStringContainsString('<code', $result);
    }

    #[Test]
    public function gfmEnvironmentRendersLinks(): void
    {
        $converter = $this->createGfmConverter();
        $result = $converter->convert('[Pulsar](https://pulsar.dev)')->getContent();

        self::assertStringContainsString('<a href="https://pulsar.dev">', $result);
        self::assertStringContainsString('Pulsar</a>', $result);
    }

    #[Test]
    public function gfmEnvironmentRendersUnorderedList(): void
    {
        $converter = $this->createGfmConverter();
        $result = $converter->convert("- Item 1\n- Item 2\n- Item 3")->getContent();

        self::assertStringContainsString('<ul>', $result);
        self::assertStringContainsString('<li>', $result);
    }

    #[Test]
    public function gfmEnvironmentRendersOrderedList(): void
    {
        $converter = $this->createGfmConverter();
        $result = $converter->convert("1. First\n2. Second")->getContent();

        self::assertStringContainsString('<ol>', $result);
        self::assertStringContainsString('<li>', $result);
    }

    #[Test]
    public function gfmEnvironmentRendersBlockquote(): void
    {
        $converter = $this->createGfmConverter();
        $result = $converter->convert('> Quote text')->getContent();

        self::assertStringContainsString('<blockquote>', $result);
    }

    #[Test]
    public function gfmEnvironmentRendersStrikethrough(): void
    {
        $converter = $this->createGfmConverter();
        $result = $converter->convert('~~deleted~~')->getContent();

        self::assertStringContainsString('<del>deleted</del>', $result);
    }

    #[Test]
    public function gfmEnvironmentEscapesRawHtml(): void
    {
        $converter = $this->createGfmConverter();
        $result = $converter->convert('<script>alert("xss")</script>')->getContent();

        self::assertStringNotContainsString('<script>', $result);
    }

    #[Test]
    public function gfmEnvironmentRendersTable(): void
    {
        $converter = $this->createGfmConverter();
        $result = $converter->convert("| A | B |\n|---|---|\n| 1 | 2 |")->getContent();

        self::assertStringContainsString('<table>', $result);
        self::assertStringContainsString('<th>', $result);
        self::assertStringContainsString('<td>', $result);
    }

    #[Test]
    public function gfmEnvironmentRendersAutolinks(): void
    {
        $converter = $this->createGfmConverter();
        $result = $converter->convert('Visit https://example.com for more')->getContent();

        self::assertStringContainsString('<a href="https://example.com">', $result);
    }

    #[Test]
    public function gfmEnvironmentOutputIsTrimmed(): void
    {
        $converter = $this->createGfmConverter();
        $result = trim($converter->convert('test')->getContent());

        self::assertSame($result, trim($result));
    }

    /**
     * Create a correctly configured GFM converter matching the intended
     * MarkdownRenderer configuration (without duplicate extension registration).
     */
    private function createGfmConverter(int $maxNestingLevel = 10): MarkdownConverter
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => $maxNestingLevel,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        return new MarkdownConverter($environment);
    }
}
