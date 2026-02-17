<?php

declare(strict_types=1);

namespace Pulsar\Tests\Property;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Escaper\ContextEscaper;

use function bin2hex;
use function htmlspecialchars_decode;
use function rawurldecode;
use function str_repeat;

/**
 * Property-based tests for ContextEscaper.
 *
 * Verifies security invariants that must hold for ANY input string:
 * - html() output never contains raw < or > characters
 * - attr() output never contains raw < or > or unescaped quotes
 * - js() output is safe for embedding in JavaScript strings
 * - url() output is RFC 3986 percent-encoded
 * - urlFull() blocks dangerous schemes for any input
 */
#[CoversClass(ContextEscaper::class)]
#[Group('property')]
final class ContextEscaperPropertyTest extends TestCase
{
    /**
     * For any string, html() output never contains unescaped < or >.
     *
     * This is the fundamental XSS prevention property: if < and > are
     * always escaped, an attacker cannot inject HTML tags.
     */
    #[Test]
    #[DataProvider('arbitraryStrings')]
    public function htmlOutputNeverContainsUnescapedAngleBrackets(string $input): void
    {
        $escaped = ContextEscaper::html($input);

        self::assertStringNotContainsString('<', $escaped, 'html() must escape < in: ' . bin2hex($input));
        self::assertStringNotContainsString('>', $escaped, 'html() must escape > in: ' . bin2hex($input));
    }

    /**
     * For any string, html() output never contains unescaped ampersands
     * (except as part of HTML entity references like &amp; &lt; &gt; &quot; &#039;).
     */
    #[Test]
    #[DataProvider('arbitraryStrings')]
    public function htmlOutputEscapesAmpersands(string $input): void
    {
        $escaped = ContextEscaper::html($input);

        // Every & in the output must be part of an HTML entity
        // Match bare & that is NOT followed by entity syntax
        self::assertDoesNotMatchRegularExpression(
            '/&(?!(?:amp|lt|gt|quot|apos|#\d+|#x[\da-fA-F]+);)/',
            $escaped,
            'html() must escape bare & in: ' . bin2hex($input),
        );
    }

    /**
     * For any string, html() output never contains unescaped double quotes.
     */
    #[Test]
    #[DataProvider('arbitraryStrings')]
    public function htmlOutputEscapesDoubleQuotes(string $input): void
    {
        $escaped = ContextEscaper::html($input);

        self::assertStringNotContainsString('"', $escaped, 'html() must escape double quotes');
    }

    /**
     * For any string, attr() has the same safety properties as html().
     */
    #[Test]
    #[DataProvider('arbitraryStrings')]
    public function attrOutputNeverContainsUnescapedAngleBrackets(string $input): void
    {
        $escaped = ContextEscaper::attr($input);

        self::assertStringNotContainsString('<', $escaped);
        self::assertStringNotContainsString('>', $escaped);
        self::assertStringNotContainsString('"', $escaped);
    }

    /**
     * html() roundtrips through decode: decode(html(x)) === x for any x.
     */
    #[Test]
    #[DataProvider('arbitraryStrings')]
    public function htmlEscapingIsReversible(string $input): void
    {
        $escaped = ContextEscaper::html($input);
        $decoded = htmlspecialchars_decode($escaped, ENT_QUOTES | ENT_HTML5);

        self::assertSame($input, $decoded, 'html() must be reversible via htmlspecialchars_decode');
    }

    /**
     * js() output never contains </script> which could break out of a script tag.
     */
    #[Test]
    #[DataProvider('arbitraryStrings')]
    public function jsOutputNeverContainsScriptCloseTag(string $input): void
    {
        $escaped = ContextEscaper::js($input);

        self::assertStringNotContainsString('</script>', $escaped, 'js() must prevent script tag injection');
        self::assertStringNotContainsString('</SCRIPT>', $escaped, 'js() must prevent script tag injection (uppercase)');
    }

    /**
     * js() output never contains raw single or double quotes that could
     * break out of a JavaScript string literal.
     */
    #[Test]
    #[DataProvider('arbitraryStrings')]
    public function jsOutputNeverContainsRawQuotes(string $input): void
    {
        $escaped = ContextEscaper::js($input);

        // JSON_HEX_APOS and JSON_HEX_QUOT encode quotes as unicode escapes
        self::assertStringNotContainsString("'", $escaped, 'js() must escape single quotes');
        self::assertStringNotContainsString('"', $escaped, 'js() must escape double quotes');
    }

    /**
     * url() output is safe as a URL component: no unencoded special characters.
     */
    #[Test]
    #[DataProvider('arbitraryStrings')]
    public function urlOutputIsPercentEncoded(string $input): void
    {
        $encoded = ContextEscaper::url($input);

        // rawurlencode preserves only unreserved characters (A-Z, a-z, 0-9, -, _, ., ~)
        // Everything else must be percent-encoded
        self::assertMatchesRegularExpression(
            '/^[A-Za-z0-9\-_.~%]*$/',
            $encoded,
            'url() output must only contain unreserved chars and percent-encoded sequences',
        );
    }

    /**
     * url() roundtrips through decode: rawurldecode(url(x)) === x for any x.
     */
    #[Test]
    #[DataProvider('arbitraryStrings')]
    public function urlEscapingIsReversible(string $input): void
    {
        $encoded = ContextEscaper::url($input);
        $decoded = rawurldecode($encoded);

        self::assertSame($input, $decoded, 'url() must be reversible via rawurldecode');
    }

    /**
     * urlFull() blocks javascript: scheme for any payload.
     */
    #[Test]
    #[DataProvider('dangerousSchemes')]
    public function urlFullBlocksDangerousSchemes(string $input): void
    {
        $result = ContextEscaper::urlFull($input);

        self::assertSame('', $result, "urlFull() must return empty for dangerous scheme: {$input}");
    }

    /**
     * urlFull() allows safe HTTP(S) URLs.
     */
    #[Test]
    #[DataProvider('safeUrls')]
    public function urlFullAllowsSafeSchemes(string $input): void
    {
        $result = ContextEscaper::urlFull($input);

        self::assertNotSame('', $result, "urlFull() must allow safe URL: {$input}");
    }

    /**
     * css() strips dangerous CSS function calls for any input.
     */
    #[Test]
    #[DataProvider('cssDangerousInputs')]
    public function cssStripsExpressionAndUrlCalls(string $input): void
    {
        $escaped = ContextEscaper::css($input);

        self::assertDoesNotMatchRegularExpression(
            '/\b(?:expression|url|import)\s*\(/i',
            $escaped,
            "css() must strip dangerous function calls from: {$input}",
        );
    }

    /**
     * stripTags() removes all HTML tags for any input.
     */
    #[Test]
    #[DataProvider('htmlWithTags')]
    public function stripTagsRemovesAllTags(string $input): void
    {
        $stripped = ContextEscaper::stripTags($input);

        self::assertDoesNotMatchRegularExpression(
            '/<[^>]+>/',
            $stripped,
            'stripTags() must remove all HTML tags',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function arbitraryStrings(): iterable
    {
        // Empty and whitespace
        yield 'empty' => [''];
        yield 'space' => [' '];
        yield 'tab' => ["\t"];
        yield 'newline' => ["\n"];
        yield 'crlf' => ["\r\n"];

        // HTML injection attempts
        yield 'script tag' => ['<script>alert("xss")</script>'];
        yield 'img onerror' => ['<img onerror="alert(1)" src=x>'];
        yield 'svg onload' => ['<svg onload="alert(1)">'];
        yield 'nested tags' => ['<div><p>text</p></div>'];
        yield 'broken tag' => ['<div'];
        yield 'close tag only' => ['</div>'];
        yield 'angle brackets no tag' => ['2 < 3 && 5 > 4'];

        // Quote injection
        yield 'double quote' => ['"quoted"'];
        yield 'single quote' => ["it's"];
        yield 'mixed quotes' => ['"it\'s a "test"'];
        yield 'attribute breakout' => ['" onclick="alert(1)"'];

        // Ampersand handling
        yield 'bare ampersand' => ['AT&T'];
        yield 'html entity' => ['&amp;'];
        yield 'numeric entity' => ['&#60;'];
        yield 'hex entity' => ['&#x3C;'];

        // URL-like strings
        yield 'url with params' => ['https://example.com?foo=bar&baz=qux'];
        yield 'url with fragment' => ['https://example.com#section'];
        yield 'mail link' => ['mailto:test@example.com'];

        // Unicode
        yield 'chinese chars' => ["\xE4\xB8\xAD\xE6\x96\x87"];
        yield 'arabic chars' => ["\xD8\xA7\xD9\x84\xD8\xB9\xD8\xB1\xD8\xA8\xD9\x8A\xD8\xA9"];
        yield 'emoji bytes' => ["\xF0\x9F\x98\x80\xF0\x9F\x8E\x89"];

        // Null bytes and control characters
        yield 'null byte' => ["\x00"];
        yield 'bell char' => ["\x07"];
        yield 'backspace' => ["\x08"];
        yield 'mixed control' => ["hello\x00world\x07test"];

        // JavaScript injection payloads
        yield 'js in href' => ['javascript:alert(document.cookie)'];
        yield 'data uri' => ['data:text/html,<script>alert(1)</script>'];
        yield 'expression css' => ['expression(alert(1))'];

        // Long strings
        yield 'repeated chars' => [str_repeat('A', 10000)];
        yield 'repeated tags' => [str_repeat('<b>bold</b>', 100)];

        // Special regex characters
        yield 'regex chars' => ['.*+?^${}()|[]\\'];
        yield 'backslash' => ['\\'];
        yield 'forward slash' => ['/'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dangerousSchemes(): iterable
    {
        yield 'javascript lowercase' => ['javascript:alert(1)'];
        yield 'javascript uppercase' => ['JAVASCRIPT:alert(1)'];
        yield 'javascript mixed case' => ['JaVaScRiPt:alert(1)'];
        yield 'data uri' => ['data:text/html,<script>alert(1)</script>'];
        yield 'data uri base64' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='];
        yield 'vbscript' => ['vbscript:msgbox("xss")'];
        yield 'javascript with spaces in payload' => ['javascript:void(0)'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function safeUrls(): iterable
    {
        yield 'https' => ['https://example.com'];
        yield 'http' => ['http://example.com'];
        yield 'mailto' => ['mailto:user@example.com'];
        yield 'relative path' => ['/about'];
        yield 'hash fragment' => ['#section'];
        yield 'query string' => ['?page=2'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cssDangerousInputs(): iterable
    {
        yield 'expression call' => ['expression(alert(1))'];
        yield 'url call' => ['url(javascript:alert(1))'];
        yield 'import call' => ['import(evil.css)'];
        yield 'nested expression' => ['color: expression(document.cookie)'];
        yield 'url with data' => ['background: url(data:text/html,<script>)'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function htmlWithTags(): iterable
    {
        yield 'paragraph' => ['<p>Hello</p>'];
        yield 'script' => ['<script>alert(1)</script>'];
        yield 'nested' => ['<div><span class="x">text</span></div>'];
        yield 'self-closing' => ['<br/><hr/>'];
        yield 'attributes' => ['<a href="http://evil.com" onclick="steal()">click</a>'];
        yield 'comment' => ['<!-- comment -->visible'];
    }
}
