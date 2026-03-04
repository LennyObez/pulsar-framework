<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Escaper;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Escaper\ContextEscaper;
use Pulsar\Security\Escaper\EscapeContext;

final class ContextEscaperTest extends TestCase
{
    #[Test]
    public function htmlEscapesSpecialCharacters(): void
    {
        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', ContextEscaper::html('<script>alert(1)</script>'));
    }

    #[Test]
    public function htmlEscapesAmpersand(): void
    {
        self::assertSame('foo &amp; bar', ContextEscaper::html('foo & bar'));
    }

    #[Test]
    public function htmlEscapesQuotes(): void
    {
        $result = ContextEscaper::html('"quoted" & \'single\'');

        self::assertStringContainsString('&quot;', $result);
        self::assertStringContainsString('&amp;', $result);
        // PHP 8.5+ with ENT_HTML5 uses &apos; for single quotes
        self::assertStringNotContainsString("'", $result);
    }

    #[Test]
    public function htmlPreservesNonSpecialCharacters(): void
    {
        self::assertSame('Hello World 123', ContextEscaper::html('Hello World 123'));
    }

    #[Test]
    public function attrEscapesForAttributeContext(): void
    {
        $result = ContextEscaper::attr('" onmouseover="alert(1)');

        self::assertStringNotContainsString('"', $result);
    }

    #[Test]
    public function jsEscapesForJavaScriptStringLiteral(): void
    {
        $result = ContextEscaper::js('</script><script>alert(1)</script>');

        self::assertStringNotContainsString('</script>', $result);
    }

    #[Test]
    public function jsEscapesSingleQuotes(): void
    {
        $result = ContextEscaper::js("it's a test");

        self::assertStringNotContainsString("'", $result);
    }

    #[Test]
    public function jsEscapesDoubleQuotes(): void
    {
        $result = ContextEscaper::js('he said "hello"');

        // json_encode converts " to \" inside the string
        self::assertStringNotContainsString('"', $result);
    }

    #[Test]
    public function jsHandlesEmptyString(): void
    {
        self::assertSame('', ContextEscaper::js(''));
    }

    #[Test]
    public function cssRemovesDangerousFunctions(): void
    {
        $result = ContextEscaper::css('expression(alert(1))');

        self::assertStringNotContainsString('expression(', $result);
    }

    #[Test]
    public function cssRemovesUrlFunction(): void
    {
        $result = ContextEscaper::css('url(javascript:alert(1))');

        self::assertStringNotContainsString('url(', $result);
    }

    #[Test]
    public function cssAllowsSafeValues(): void
    {
        $result = ContextEscaper::css('red');

        self::assertSame('red', $result);
    }

    #[Test]
    public function cssAllowsColorHex(): void
    {
        $result = ContextEscaper::css('#ff0000');

        self::assertSame('#ff0000', $result);
    }

    #[Test]
    public function urlEncodesSpecialCharacters(): void
    {
        self::assertSame('hello%20world', ContextEscaper::url('hello world'));
        self::assertSame('%3Cscript%3E', ContextEscaper::url('<script>'));
    }

    #[Test]
    public function urlFullBlocksJavascriptScheme(): void
    {
        self::assertSame('', ContextEscaper::urlFull('javascript:alert(1)'));
    }

    #[Test]
    public function urlFullBlocksDataScheme(): void
    {
        self::assertSame('', ContextEscaper::urlFull('data:text/html,<script>alert(1)</script>'));
    }

    #[Test]
    public function urlFullBlocksVbscriptScheme(): void
    {
        self::assertSame('', ContextEscaper::urlFull('vbscript:MsgBox("XSS")'));
    }

    #[Test]
    public function urlFullAllowsHttpScheme(): void
    {
        $url = 'https://example.com/path?q=1';
        $result = ContextEscaper::urlFull($url);

        self::assertStringContainsString('example.com', $result);
    }

    #[Test]
    public function urlFullAllowsRelativeUrls(): void
    {
        self::assertNotEmpty(ContextEscaper::urlFull('/path/to/page'));
        self::assertNotEmpty(ContextEscaper::urlFull('#anchor'));
        self::assertNotEmpty(ContextEscaper::urlFull('?query=1'));
    }

    #[Test]
    public function urlFullBlocksUnknownSchemes(): void
    {
        self::assertSame('', ContextEscaper::urlFull('file:///etc/passwd'));
    }

    #[Test]
    public function stripTagsRemovesAllHtml(): void
    {
        $result = ContextEscaper::stripTags('<p>Hello <b>World</b></p>');

        self::assertSame('Hello World', $result);
    }

    #[Test]
    #[DataProvider('escapeContextProvider')]
    public function escapeDispatchesToCorrectMethod(EscapeContext $context, string $input, string $expected): void
    {
        $result = ContextEscaper::escape($input, $context);

        self::assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{EscapeContext, string, string}>
     */
    public static function escapeContextProvider(): iterable
    {
        yield 'html' => [EscapeContext::Html, '<b>', '&lt;b&gt;'];
        yield 'attr' => [EscapeContext::Attribute, '"test"', '&quot;test&quot;'];
        yield 'url' => [EscapeContext::Url, 'hello world', 'hello%20world'];
    }

    #[Test]
    public function htmlHandlesUtf8Correctly(): void
    {
        $input = 'Cafe & "Lounge" <Paris>';
        $result = ContextEscaper::html($input);

        self::assertSame('Cafe &amp; &quot;Lounge&quot; &lt;Paris&gt;', $result);
    }

    #[Test]
    public function urlFullCaseInsensitiveSchemeBlocking(): void
    {
        self::assertSame('', ContextEscaper::urlFull('JAVASCRIPT:alert(1)'));
        self::assertSame('', ContextEscaper::urlFull('JavaScript:alert(1)'));
    }
}
