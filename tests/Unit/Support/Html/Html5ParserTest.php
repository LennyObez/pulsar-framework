<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Support\Html;

use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Support\Html\Html5Parser;

#[CoversClass(Html5Parser::class)]
final class Html5ParserTest extends TestCase
{
    public function testParseReturnsHtmlDocument(): void
    {
        $doc = Html5Parser::parse('<p>Hello</p>');

        self::assertInstanceOf(HTMLDocument::class, $doc);
    }

    public function testParseHandlesFullDocument(): void
    {
        $html = '<!DOCTYPE html><html><head><title>Test</title></head><body><p>Content</p></body></html>';
        $doc = Html5Parser::parse($html);

        self::assertNotNull($doc->body);
        self::assertIsString($doc->body->textContent);
        self::assertStringContainsString('Content', $doc->body->textContent);
    }

    public function testExtractTextReturnsVisibleContent(): void
    {
        $html = '<div><p>Hello</p><p>World</p></div>';
        $text = Html5Parser::extractText($html);

        self::assertSame('Hello World', $text);
    }

    public function testExtractTextExcludesScriptContent(): void
    {
        $html = '<div><p>Visible</p><script>var secret = "hidden";</script></div>';
        $text = Html5Parser::extractText($html);

        self::assertSame('Visible', $text);
        self::assertStringNotContainsString('secret', $text);
    }

    public function testExtractTextExcludesStyleContent(): void
    {
        $html = '<div><p>Visible</p><style>.hidden { display: none; }</style></div>';
        $text = Html5Parser::extractText($html);

        self::assertSame('Visible', $text);
        self::assertStringNotContainsString('hidden', $text);
    }

    public function testExtractTextExcludesNoscriptContent(): void
    {
        $html = '<div><p>Main</p><noscript>Enable JS</noscript></div>';
        $text = Html5Parser::extractText($html);

        self::assertSame('Main', $text);
    }

    public function testExtractTextExcludesTemplateContent(): void
    {
        $html = '<div><p>Real</p><template><p>Template content</p></template></div>';
        $text = Html5Parser::extractText($html);

        self::assertStringNotContainsString('Template content', $text);
    }

    public function testExtractTextCustomExcludeTags(): void
    {
        $html = '<div><p>Keep</p><nav>Skip nav</nav><footer>Skip footer</footer></div>';
        $text = Html5Parser::extractText($html, ['nav', 'footer']);

        self::assertSame('Keep', $text);
    }

    public function testExtractTextFromEmptyHtml(): void
    {
        self::assertSame('', Html5Parser::extractText(''));
    }

    public function testQuerySelectorAllFindsElements(): void
    {
        $html = '<ul><li class="item">A</li><li class="item">B</li><li>C</li></ul>';
        $elements = Html5Parser::querySelectorAll($html, 'li.item');

        self::assertCount(2, $elements);
        self::assertSame('A', $elements[0]->textContent);
        self::assertSame('B', $elements[1]->textContent);
    }

    public function testQuerySelectorAllReturnsEmptyForNoMatch(): void
    {
        $html = '<p>Hello</p>';
        $elements = Html5Parser::querySelectorAll($html, 'div.nonexistent');

        self::assertSame([], $elements);
    }

    public function testQuerySelectorReturnsFirstMatch(): void
    {
        $html = '<div><span>First</span><span>Second</span></div>';
        $element = Html5Parser::querySelector($html, 'span');

        self::assertNotNull($element);
        self::assertSame('First', $element->textContent);
    }

    public function testQuerySelectorReturnsNullForNoMatch(): void
    {
        $html = '<p>Hello</p>';
        $element = Html5Parser::querySelector($html, 'div');

        self::assertNull($element);
    }

    public function testExtractAttributesCollectsValues(): void
    {
        $html = '<div><a href="/page1">A</a><a href="/page2">B</a><a>C</a></div>';
        $hrefs = Html5Parser::extractAttributes($html, 'a', 'href');

        self::assertSame(['/page1', '/page2'], $hrefs);
    }

    public function testExtractAttributesSkipsEmptyValues(): void
    {
        $html = '<div><img src="a.jpg" alt=""><img src="b.jpg" alt="Photo"></div>';
        $alts = Html5Parser::extractAttributes($html, 'img', 'alt');

        self::assertSame(['Photo'], array_values($alts));
    }

    public function testSanitizeRemovesScriptTags(): void
    {
        $html = '<p>Safe</p><script>alert("xss")</script>';
        $clean = Html5Parser::sanitize($html);

        self::assertStringContainsString('Safe', $clean);
        self::assertStringNotContainsString('script', $clean);
        self::assertStringNotContainsString('alert', $clean);
    }

    public function testSanitizeRemovesEventHandlers(): void
    {
        $html = '<p onclick="alert(1)">Click me</p>';
        $clean = Html5Parser::sanitize($html);

        self::assertStringContainsString('Click me', $clean);
        self::assertStringNotContainsString('onclick', $clean);
    }

    public function testSanitizeRemovesJavascriptUrls(): void
    {
        $html = '<a href="javascript:alert(1)">Link</a>';
        $clean = Html5Parser::sanitize($html);

        self::assertStringContainsString('Link', $clean);
        self::assertStringNotContainsString('javascript', $clean);
    }

    public function testSanitizeKeepsAllowedTags(): void
    {
        $html = '<p>Text with <strong>bold</strong> and <em>italic</em></p>';
        $clean = Html5Parser::sanitize($html);

        self::assertStringContainsString('<p>', $clean);
        self::assertStringContainsString('<strong>', $clean);
        self::assertStringContainsString('<em>', $clean);
    }

    public function testSanitizeKeepsAriaAttributes(): void
    {
        $html = '<div role="alert" aria-live="polite">Warning</div>';
        $clean = Html5Parser::sanitize($html);

        self::assertStringContainsString('role="alert"', $clean);
        self::assertStringContainsString('aria-live="polite"', $clean);
    }

    public function testSanitizeKeepsDataAttributes(): void
    {
        $html = '<div data-id="123" data-action="toggle">Content</div>';
        $clean = Html5Parser::sanitize($html);

        self::assertStringContainsString('data-id="123"', $clean);
        self::assertStringContainsString('data-action="toggle"', $clean);
    }

    public function testSanitizeCustomAllowedTags(): void
    {
        $html = '<p>Keep</p><div>Remove</div><span>Also keep</span>';
        $clean = Html5Parser::sanitize($html, allowedTags: ['p', 'span']);

        self::assertStringContainsString('<p>', $clean);
        self::assertStringContainsString('<span>', $clean);
        self::assertStringNotContainsString('<div>', $clean);
    }

    public function testSanitizeEmptyHtml(): void
    {
        $clean = Html5Parser::sanitize('');

        // Empty HTML still produces a body wrapper from the HTML5 parser
        self::assertStringNotContainsString('script', $clean);
    }

    public function testValidateReportsImagesWithoutAlt(): void
    {
        $html = '<div><img src="photo.jpg"></div>';
        $issues = Html5Parser::validate($html);

        self::assertNotEmpty($issues);
        self::assertStringContainsString('alt attribute', $issues[0]);
    }

    public function testValidatePassesImagesWithAlt(): void
    {
        $html = '<div><img src="photo.jpg" alt="A nice photo"></div>';
        $issues = Html5Parser::validate($html);

        $imgIssues = array_filter($issues, static fn(string $s): bool => str_contains($s, 'alt'));
        self::assertEmpty($imgIssues);
    }

    public function testValidateReportsSkippedHeadingLevels(): void
    {
        $html = '<div><h1>Title</h1><h3>Subtitle</h3></div>';
        $issues = Html5Parser::validate($html);

        self::assertNotEmpty($issues);
        self::assertStringContainsString('Heading level skipped', $issues[0]);
    }

    public function testValidateAcceptsProperHeadingHierarchy(): void
    {
        $html = '<div><h1>Title</h1><h2>Subtitle</h2><h3>Section</h3></div>';
        $issues = Html5Parser::validate($html);

        $headingIssues = array_filter($issues, static fn(string $s): bool => str_contains($s, 'Heading'));
        self::assertEmpty($headingIssues);
    }

    public function testValidateReportsLinksWithoutHref(): void
    {
        $html = '<div><a>Broken link</a></div>';
        $issues = Html5Parser::validate($html);

        self::assertNotEmpty($issues);
        self::assertStringContainsString('href', $issues[0]);
    }

    public function testValidateReportsEmptyButtons(): void
    {
        $html = '<button></button>';
        $issues = Html5Parser::validate($html);

        self::assertNotEmpty($issues);
        self::assertStringContainsString('Button', $issues[0]);
    }

    public function testValidateAcceptsButtonWithAriaLabel(): void
    {
        $html = '<button aria-label="Close"></button>';
        $issues = Html5Parser::validate($html);

        $btnIssues = array_filter($issues, static fn(string $s): bool => str_contains($s, 'Button'));
        self::assertEmpty($btnIssues);
    }

    public function testValidateEmptyHtmlProducesNoIssues(): void
    {
        // HTML5 parser always produces a body even for empty input
        $issues = Html5Parser::validate('');

        // No structural issues in an empty document
        self::assertSame([], $issues);
    }
}
