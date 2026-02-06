<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Escaping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Escaping\AttributeEscaper;
use Pulsar\View\Escaping\CssEscaper;
use Pulsar\View\Escaping\EscaperInterface;
use Pulsar\View\Escaping\HtmlEscaper;
use Pulsar\View\Escaping\JsEscaper;
use Pulsar\View\Escaping\UrlEscaper;

#[CoversClass(HtmlEscaper::class)]
#[CoversClass(UrlEscaper::class)]
#[CoversClass(AttributeEscaper::class)]
#[CoversClass(JsEscaper::class)]
#[CoversClass(CssEscaper::class)]
final class EscaperTest extends TestCase
{
    // --- HtmlEscaper ---

    #[Test]
    public function htmlEscaperImplementsInterface(): void
    {
        self::assertInstanceOf(EscaperInterface::class, new HtmlEscaper());
    }

    #[Test]
    public function htmlEscaperEscapesBasicEntities(): void
    {
        $escaper = new HtmlEscaper();

        self::assertSame('&lt;script&gt;', $escaper->escape('<script>'));
        self::assertSame('&amp;amp;', $escaper->escape('&amp;'));
        self::assertSame('&quot;quoted&quot;', $escaper->escape('"quoted"'));
        self::assertSame('&#039;apostrophe&#039;', $escaper->escape("'apostrophe'"));
    }

    #[Test]
    public function htmlEscaperPreservesPlainText(): void
    {
        $escaper = new HtmlEscaper();

        self::assertSame('Hello World', $escaper->escape('Hello World'));
        self::assertSame('123', $escaper->escape('123'));
    }

    #[Test]
    public function htmlEscaperHandlesEmptyString(): void
    {
        self::assertSame('', new HtmlEscaper()->escape(''));
    }

    // --- UrlEscaper ---

    #[Test]
    public function urlEscaperImplementsInterface(): void
    {
        self::assertInstanceOf(EscaperInterface::class, new UrlEscaper());
    }

    #[Test]
    public function urlEscaperPreservesNormalUrls(): void
    {
        $escaper = new UrlEscaper();

        self::assertSame('https://example.com/path?q=1', $escaper->escape('https://example.com/path?q=1'));
        self::assertSame('/relative/path', $escaper->escape('/relative/path'));
    }

    #[Test]
    public function urlEscaperBlocksJavascriptScheme(): void
    {
        $escaper = new UrlEscaper();

        self::assertSame('', $escaper->escape('javascript:alert(1)'));
        self::assertSame('', $escaper->escape('JAVASCRIPT:alert(1)'));
        self::assertSame('', $escaper->escape('JavaScript:alert(1)'));
    }

    #[Test]
    public function urlEscaperBlocksDataScheme(): void
    {
        $escaper = new UrlEscaper();

        self::assertSame('', $escaper->escape('data:text/html,<script>alert(1)</script>'));
        self::assertSame('', $escaper->escape('DATA:text/html,test'));
    }

    #[Test]
    public function urlEscaperBlocksVbscriptScheme(): void
    {
        $escaper = new UrlEscaper();

        self::assertSame('', $escaper->escape('vbscript:MsgBox("XSS")'));
    }

    #[Test]
    public function urlEscaperBlocksSchemeWithWhitespace(): void
    {
        $escaper = new UrlEscaper();

        // Whitespace-padded scheme attempts
        self::assertSame('', $escaper->escape(' javascript:alert(1)'));
        self::assertSame('', $escaper->escape("\tjavascript:alert(1)"));
        self::assertSame('', $escaper->escape("\njavascript:alert(1)"));
    }

    #[Test]
    public function urlEscaperEncodesSpaces(): void
    {
        $escaper = new UrlEscaper();

        self::assertStringContainsString('%20', $escaper->escape('https://example.com/path with spaces'));
    }

    // --- AttributeEscaper ---

    #[Test]
    public function attributeEscaperImplementsInterface(): void
    {
        self::assertInstanceOf(EscaperInterface::class, new AttributeEscaper());
    }

    #[Test]
    public function attributeEscaperEncodesNonAlphanumeric(): void
    {
        $escaper = new AttributeEscaper();

        $result = $escaper->escape('" onmouseover="alert(1)');

        self::assertStringNotContainsString('"', $result);
        self::assertStringNotContainsString('=', $result);
        self::assertStringContainsString('&#34;', $result);
    }

    #[Test]
    public function attributeEscaperPreservesAlphanumeric(): void
    {
        $escaper = new AttributeEscaper();

        self::assertSame('HelloWorld123', $escaper->escape('HelloWorld123'));
    }

    #[Test]
    public function attributeEscaperHandlesEmptyString(): void
    {
        self::assertSame('', new AttributeEscaper()->escape(''));
    }

    #[Test]
    public function attributeEscaperHandlesMultiByteCharacters(): void
    {
        $escaper = new AttributeEscaper();

        // U+00E9 (e-acute) — codepoint 233
        $result = $escaper->escape("\xC3\xA9");
        self::assertSame('&#233;', $result);

        // U+2603 (snowman) — codepoint 9731
        $result = $escaper->escape("\xE2\x98\x83");
        self::assertSame('&#9731;', $result);

        // Mixed ASCII + multi-byte
        $result = $escaper->escape("caf\xC3\xA9");
        self::assertSame('caf&#233;', $result);
    }

    // --- JsEscaper ---

    #[Test]
    public function jsEscaperImplementsInterface(): void
    {
        self::assertInstanceOf(EscaperInterface::class, new JsEscaper());
    }

    #[Test]
    public function jsEscaperEscapesHtmlTags(): void
    {
        $escaper = new JsEscaper();

        $result = $escaper->escape('</script><script>alert(1)</script>');

        self::assertStringNotContainsString('</script>', $result);
        self::assertStringNotContainsString('<script>', $result);
    }

    #[Test]
    public function jsEscaperEscapesQuotes(): void
    {
        $escaper = new JsEscaper();

        $result = $escaper->escape('He said "hello" & \'goodbye\'');

        self::assertStringNotContainsString('"', $result);
        self::assertStringNotContainsString("'", $result);
    }

    #[Test]
    public function jsEscaperPreservesPlainText(): void
    {
        $escaper = new JsEscaper();

        self::assertSame('Hello World', $escaper->escape('Hello World'));
    }

    #[Test]
    public function jsEscaperHandlesEmptyString(): void
    {
        self::assertSame('', new JsEscaper()->escape(''));
    }

    // --- CssEscaper ---

    #[Test]
    public function cssEscaperImplementsInterface(): void
    {
        self::assertInstanceOf(EscaperInterface::class, new CssEscaper());
    }

    #[Test]
    public function cssEscaperEncodesNonAlphanumeric(): void
    {
        $escaper = new CssEscaper();

        $result = $escaper->escape('expression(alert(1))');

        self::assertStringNotContainsString('(', $result);
        self::assertStringNotContainsString(')', $result);
        self::assertStringContainsString('\\', $result);
    }

    #[Test]
    public function cssEscaperPreservesAlphanumeric(): void
    {
        $escaper = new CssEscaper();

        self::assertSame('red', $escaper->escape('red'));
        self::assertSame('100px', $escaper->escape('100px'));
    }

    #[Test]
    public function cssEscaperEscapesUrl(): void
    {
        $escaper = new CssEscaper();

        $result = $escaper->escape('url(javascript:alert(1))');

        // The parentheses and colon are escaped, making the expression non-functional
        self::assertStringNotContainsString('(', $result);
        self::assertStringNotContainsString(':', $result);
    }

    #[Test]
    public function cssEscaperHandlesEmptyString(): void
    {
        self::assertSame('', new CssEscaper()->escape(''));
    }

    #[Test]
    public function cssEscaperHandlesMultiByteCharacters(): void
    {
        $escaper = new CssEscaper();

        // U+00E9 (e-acute) — codepoint 233 = 0xE9
        $result = $escaper->escape("\xC3\xA9");
        self::assertSame('\\e9 ', $result);

        // U+2603 (snowman) — codepoint 9731 = 0x2603
        $result = $escaper->escape("\xE2\x98\x83");
        self::assertSame('\\2603 ', $result);

        // Mixed ASCII + multi-byte
        $result = $escaper->escape("caf\xC3\xA9");
        self::assertSame('caf\\e9 ', $result);
    }

    // --- OWASP XSS Prevention Vectors ---

    #[Test]
    public function htmlEscaperBlocksScriptInjection(): void
    {
        $escaper = new HtmlEscaper();

        $vectors = [
            '<script>alert("XSS")</script>',
            '<img src=x onerror=alert(1)>',
            '<svg onload=alert(1)>',
            '<body onload=alert(1)>',
            '<iframe src="javascript:alert(1)">',
            '<input onfocus=alert(1) autofocus>',
            '<marquee onstart=alert(1)>',
            '<div style="background:url(javascript:alert(1))">',
        ];

        foreach ($vectors as $vector) {
            $escaped = $escaper->escape($vector);

            self::assertStringNotContainsString('<script>', $escaped, "Failed to escape: {$vector}");
            self::assertStringNotContainsString('<img', $escaped, "Failed to escape: {$vector}");
            self::assertStringNotContainsString('<svg', $escaped, "Failed to escape: {$vector}");
            self::assertStringNotContainsString('<body', $escaped, "Failed to escape: {$vector}");
            self::assertStringNotContainsString('<iframe', $escaped, "Failed to escape: {$vector}");
            self::assertStringNotContainsString('<input', $escaped, "Failed to escape: {$vector}");
        }
    }

    #[Test]
    public function urlEscaperBlocksOwaspUrlVectors(): void
    {
        $escaper = new UrlEscaper();

        $vectors = [
            'javascript:alert(document.domain)',
            'javascript:alert(String.fromCharCode(88,83,83))',
            "javascript\t:alert(1)",
            'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
            'vbscript:MsgBox("XSS")',
        ];

        foreach ($vectors as $vector) {
            $escaped = $escaper->escape($vector);

            self::assertSame('', $escaped, "Failed to block dangerous URI: {$vector}");
        }
    }

    #[Test]
    public function urlEscaperBlocksUnicodeWhitespaceSchemeBypass(): void
    {
        $escaper = new UrlEscaper();

        $vectors = [
            // Zero-width space (U+200B) inside scheme
            "java\xE2\x80\x8Bscript:alert(1)",
            // Zero-width non-joiner (U+200C)
            "java\xE2\x80\x8Cscript:alert(1)",
            // Soft hyphen (U+00AD)
            "java\xC2\xADscript:alert(1)",
            // BOM (U+FEFF)
            "\xEF\xBB\xBFjavascript:alert(1)",
        ];

        foreach ($vectors as $vector) {
            $escaped = $escaper->escape($vector);

            self::assertSame('', $escaped, 'Failed to block Unicode whitespace scheme bypass');
        }
    }

    #[Test]
    public function attributeEscaperBlocksEventHandlerInjection(): void
    {
        $escaper = new AttributeEscaper();

        $vectors = [
            '" onmouseover="alert(1)',
            "' onfocus='alert(1)",
            '" style="background:url(javascript:alert(1))',
            '" onclick="alert(document.cookie)',
        ];

        foreach ($vectors as $vector) {
            $escaped = $escaper->escape($vector);

            self::assertStringNotContainsString('"', $escaped, "Failed to escape quotes: {$vector}");
            self::assertStringNotContainsString("'", $escaped, "Failed to escape apostrophes: {$vector}");
            self::assertStringNotContainsString('=', $escaped, "Failed to escape equals: {$vector}");
        }
    }

    #[Test]
    public function jsEscaperBlocksScriptBreakout(): void
    {
        $escaper = new JsEscaper();

        $vectors = [
            '</script><script>alert(1)</script>',
            "'; alert(1); '",
            '"; alert(1); "',
            '\'; alert(1); \'',
        ];

        foreach ($vectors as $vector) {
            $escaped = $escaper->escape($vector);

            self::assertStringNotContainsString('</script>', $escaped, "Failed to escape: {$vector}");
        }
    }

    #[Test]
    public function cssEscaperBlocksExpressionInjection(): void
    {
        $escaper = new CssEscaper();

        $vectors = [
            'expression(alert(1))',
            'url(javascript:alert(1))',
            '; background: url(evil.com)',
            '} body { color: red } .x {',
        ];

        foreach ($vectors as $vector) {
            $escaped = $escaper->escape($vector);

            self::assertStringNotContainsString('(', $escaped, "Failed to escape parens: {$vector}");
            self::assertStringNotContainsString(';', $escaped, "Failed to escape semicolon: {$vector}");
            self::assertStringNotContainsString('{', $escaped, "Failed to escape brace: {$vector}");
        }
    }
}
