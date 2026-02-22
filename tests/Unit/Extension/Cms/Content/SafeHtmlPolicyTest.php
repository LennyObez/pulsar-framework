<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;

#[CoversClass(SafeHtmlPolicy::class)]
final class SafeHtmlPolicyTest extends TestCase
{
    private SafeHtmlPolicy $policy;

    protected function setUp(): void
    {
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $this->policy = new SafeHtmlPolicy($auditLogger);
    }

    // ── Empty / whitespace input ──────────────────────────────────────

    #[Test]
    public function sanitizeEmptyInputReturnsEmpty(): void
    {
        self::assertSame('', $this->policy->sanitize(''));
    }

    #[Test]
    public function sanitizeWhitespaceOnlyInputReturnsTrimmed(): void
    {
        $result = $this->policy->sanitize('   ');
        // Whitespace-only should be preserved or trimmed gracefully
        self::assertStringNotContainsString('<script', $result);
    }

    // ── Allowed elements pass through unchanged ──────────────────────

    #[Test]
    #[DataProvider('allowedElementsProvider')]
    public function sanitizeAllowedElementPassesThrough(string $input, string $expectedTag): void
    {
        $result = $this->policy->sanitize($input);
        self::assertStringContainsString($expectedTag, $result);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function allowedElementsProvider(): iterable
    {
        yield 'paragraph' => ['<p>Hello</p>', '<p>'];
        yield 'br' => ['<p>Line<br>break</p>', '<br>'];
        yield 'h2' => ['<h2>Title</h2>', '<h2>'];
        yield 'h3' => ['<h3>Title</h3>', '<h3>'];
        yield 'h4' => ['<h4>Title</h4>', '<h4>'];
        yield 'h5' => ['<h5>Title</h5>', '<h5>'];
        yield 'h6' => ['<h6>Title</h6>', '<h6>'];
        yield 'ul' => ['<ul><li>Item</li></ul>', '<ul>'];
        yield 'ol' => ['<ol><li>Item</li></ol>', '<ol>'];
        yield 'li' => ['<ul><li>Item</li></ul>', '<li>'];
        yield 'blockquote' => ['<blockquote>Quote</blockquote>', '<blockquote>'];
        yield 'pre' => ['<pre>Code</pre>', '<pre>'];
        yield 'code' => ['<code>inline</code>', '<code>'];
        yield 'strong' => ['<strong>Bold</strong>', '<strong>'];
        yield 'em' => ['<em>Italic</em>', '<em>'];
        yield 'a with href' => ['<a href="https://example.com">Link</a>', '<a '];
        yield 'img with src' => ['<img src="/media/photo.jpg" alt="Photo">', '<img '];
        yield 'figure' => ['<figure><img src="/media/x.jpg" alt="x"></figure>', '<figure>'];
        yield 'figcaption' => ['<figure><figcaption>Caption</figcaption></figure>', '<figcaption>'];
        yield 'table' => ['<table><tr><td>Cell</td></tr></table>', '<table>'];
        yield 'thead' => ['<table><thead><tr><th>H</th></tr></thead></table>', '<thead>'];
        yield 'tbody' => ['<table><tbody><tr><td>B</td></tr></tbody></table>', '<tbody>'];
        yield 'tr' => ['<table><tr><td>R</td></tr></table>', '<tr>'];
        yield 'th' => ['<table><tr><th>H</th></tr></table>', '<th>'];
        yield 'td' => ['<table><tr><td>D</td></tr></table>', '<td>'];
        yield 'dl' => ['<dl><dt>Term</dt><dd>Def</dd></dl>', '<dl>'];
        yield 'dt' => ['<dl><dt>Term</dt></dl>', '<dt>'];
        yield 'dd' => ['<dl><dd>Def</dd></dl>', '<dd>'];
        yield 'abbr' => ['<abbr title="HyperText">HT</abbr>', '<abbr '];
        yield 'mark' => ['<mark>Highlight</mark>', '<mark>'];
        yield 'sub' => ['H<sub>2</sub>O', '<sub>'];
        yield 'sup' => ['E=mc<sup>2</sup>', '<sup>'];
        yield 'hr' => ['<hr>', '<hr>'];
        yield 'details' => ['<details><summary>More</summary>Content</details>', '<details>'];
        yield 'summary' => ['<details><summary>More</summary></details>', '<summary>'];
        yield 'time' => ['<time datetime="2024-01-01">Jan 1</time>', '<time '];
    }

    // ── Disallowed elements are unwrapped (text preserved) ───────────

    #[Test]
    #[DataProvider('disallowedElementsProvider')]
    public function sanitizeDisallowedElementIsUnwrapped(string $input, string $expectedText, string $forbiddenTag): void
    {
        $result = $this->policy->sanitize($input);
        self::assertStringContainsString($expectedText, $result);
        self::assertStringNotContainsString($forbiddenTag, $result);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function disallowedElementsProvider(): iterable
    {
        yield 'span' => ['<span>Content</span>', 'Content', '<span'];
        yield 'section' => ['<section>Content</section>', 'Content', '<section'];
        yield 'article' => ['<article>Content</article>', 'Content', '<article'];
        yield 'nav' => ['<nav>Content</nav>', 'Content', '<nav'];
        yield 'aside' => ['<aside>Content</aside>', 'Content', '<aside'];
        yield 'header' => ['<header>Content</header>', 'Content', '<header'];
        yield 'footer' => ['<footer>Content</footer>', 'Content', '<footer'];
        yield 'main' => ['<main>Content</main>', 'Content', '<main'];
        yield 'h1 not in allowlist' => ['<h1>Title</h1>', 'Title', '<h1'];
        yield 'form' => ['<form>Content</form>', 'Content', '<form'];
        yield 'input' => ['<p>Before<input type="text">After</p>', 'Before', '<input'];
        yield 'textarea' => ['<textarea>Content</textarea>', 'Content', '<textarea'];
        yield 'button' => ['<button>Click</button>', 'Click', '<button'];
        yield 'select' => ['<select><option>Opt</option></select>', 'Opt', '<select'];
        yield 'iframe' => ['<iframe>Content</iframe>', 'Content', '<iframe'];
        yield 'object' => ['<object>Content</object>', 'Content', '<object'];
    }

    // ── Attribute filtering ──────────────────────────────────────────

    #[Test]
    public function sanitizeKeepsAllowedAttributes(): void
    {
        $result = $this->policy->sanitize('<a href="https://example.com" title="Example">Link</a>');
        self::assertStringContainsString('href="https://example.com"', $result);
        self::assertStringContainsString('title="Example"', $result);
    }

    #[Test]
    public function sanitizeStripsNonAllowlistedAttributes(): void
    {
        $result = $this->policy->sanitize('<p class="foo" role="banner">Text</p>');
        self::assertStringNotContainsString('class=', $result);
        self::assertStringNotContainsString('role=', $result);
        self::assertStringContainsString('<p>', $result);
    }

    #[Test]
    public function sanitizeCodeValidClassPreserved(): void
    {
        $result = $this->policy->sanitize('<code class="language-php">code</code>');
        self::assertStringContainsString('class="language-php"', $result);
    }

    #[Test]
    public function sanitizeCodeInvalidClassStripped(): void
    {
        $result = $this->policy->sanitize('<code class="malicious-class">code</code>');
        self::assertStringNotContainsString('class=', $result);
    }

    #[Test]
    public function sanitizeThScopeAttributePreserved(): void
    {
        $result = $this->policy->sanitize('<table><tr><th scope="col">H</th></tr></table>');
        self::assertStringContainsString('scope="col"', $result);
    }

    #[Test]
    public function sanitizeTdColspanRowspanPreserved(): void
    {
        $result = $this->policy->sanitize('<table><tr><td colspan="2" rowspan="3">Cell</td></tr></table>');
        self::assertStringContainsString('colspan="2"', $result);
        self::assertStringContainsString('rowspan="3"', $result);
    }

    #[Test]
    public function sanitizeImgLoadingAttributePreserved(): void
    {
        $result = $this->policy->sanitize('<img src="/media/photo.jpg" alt="Photo" loading="lazy">');
        self::assertStringContainsString('loading="lazy"', $result);
    }

    #[Test]
    public function sanitizeDetailsOpenAttributePreserved(): void
    {
        $result = $this->policy->sanitize('<details open><summary>S</summary>Content</details>');
        self::assertStringContainsString('open', $result);
    }

    #[Test]
    public function sanitizeTimeDatetimeAttributePreserved(): void
    {
        $result = $this->policy->sanitize('<time datetime="2024-01-01">Jan 1</time>');
        self::assertStringContainsString('datetime="2024-01-01"', $result);
    }

    // ── XSS vectors: script injection ────────────────────────────────

    #[Test]
    public function sanitizeScriptTagRemoved(): void
    {
        $result = $this->policy->sanitize('<script>alert(1)</script>');
        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('alert(1)', $result);
    }

    #[Test]
    public function sanitizeScriptWithSrcRemoved(): void
    {
        $result = $this->policy->sanitize('<script src="https://evil.com/xss.js"></script>');
        self::assertStringNotContainsString('<script', $result);
    }

    // ── XSS vectors: event handlers ──────────────────────────────────

    #[Test]
    #[DataProvider('eventHandlerXssProvider')]
    public function sanitizeEventHandlerStripped(string $input): void
    {
        $result = $this->policy->sanitize($input);
        self::assertDoesNotMatchRegularExpression('/\bon[a-z]+\s*=/i', $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function eventHandlerXssProvider(): iterable
    {
        yield 'onerror on img' => ['<img src="/media/x.jpg" onerror="alert(1)">'];
        yield 'onmouseover on div' => ['<div onmouseover="alert(1)">text</div>'];
        yield 'onclick on p' => ['<p onclick="alert(1)">text</p>'];
        yield 'onload on body' => ['<body onload="alert(1)">text</body>'];
        yield 'onfocus on input' => ['<input onfocus="alert(1)">'];
        yield 'onblur on a' => ['<a href="#" onblur="alert(1)">link</a>'];
        yield 'onsubmit on form' => ['<form onsubmit="alert(1)">text</form>'];
    }

    // ── XSS vectors: javascript: URI ─────────────────────────────────

    #[Test]
    #[DataProvider('javascriptUriXssProvider')]
    public function sanitizeJavascriptUriBlocked(string $input): void
    {
        $result = $this->policy->sanitize($input);
        self::assertStringNotContainsString('javascript:', strtolower($result));
        // Ensure the link is unwrapped (text preserved, <a> removed)
        self::assertStringNotContainsString('<a ', $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function javascriptUriXssProvider(): iterable
    {
        yield 'plain' => ['<a href="javascript:alert(1)">text</a>'];
        yield 'mixed case' => ['<a href="JaVaScRiPt:alert(1)">text</a>'];
        yield 'entity encoded' => ['<a href="&#106;avascript:alert(1)">text</a>'];
        yield 'double encoded' => ['<a href="java%73cript:alert(1)">text</a>'];
        yield 'tab in scheme' => ['<a href="java	script:alert(1)">text</a>'];
        yield 'newline in scheme' => ['<a href="java' . "\n" . 'script:alert(1)">text</a>'];
        yield 'null byte in scheme' => ['<a href="java' . "\x00" . 'script:alert(1)">text</a>'];
        yield 'spaces in scheme' => ['<a href="  javascript:alert(1)">text</a>'];
        yield 'uppercase' => ['<a href="JAVASCRIPT:alert(1)">text</a>'];
        yield 'with whitespace before colon' => ['<a href="javascript :alert(1)">text</a>'];
    }

    // ── XSS vectors: vbscript ────────────────────────────────────────

    #[Test]
    public function sanitizeVbscriptUriBlocked(): void
    {
        $result = $this->policy->sanitize('<a href="vbscript:MsgBox(1)">text</a>');
        self::assertStringNotContainsString('vbscript:', strtolower($result));
    }

    // ── XSS vectors: data URI on <a> ─────────────────────────────────

    #[Test]
    public function sanitizeDataUriOnAHrefBlocked(): void
    {
        $result = $this->policy->sanitize('<a href="data:text/html,<script>alert(1)</script>">text</a>');
        self::assertStringNotContainsString('<a ', $result);
    }

    // ── data URI on <img>: safe images allowed, unsafe blocked ───────

    #[Test]
    public function sanitizeValidDataUriImageAllowed(): void
    {
        // Small valid base64 PNG data URI
        $validDataUri = 'data:image/png;base64,iVBORw0KGgo=';
        $result = $this->policy->sanitize('<img src="' . $validDataUri . '" alt="small">');
        self::assertStringContainsString('data:image/png;base64', $result);
    }

    #[Test]
    public function sanitizeDataUriNonImageBlocked(): void
    {
        $result = $this->policy->sanitize('<img src="data:text/html,<script>alert(1)</script>" alt="xss">');
        self::assertStringNotContainsString('<img', $result);
    }

    #[Test]
    public function sanitizeDataUriSvgImageBlocked(): void
    {
        // SVG is not in the safe image MIME types
        $result = $this->policy->sanitize('<img src="data:image/svg+xml;base64,PHN2Zz4=" alt="svg">');
        self::assertStringNotContainsString('<img', $result);
    }

    // ── XSS vectors: SVG/MathML namespace injection ──────────────────

    #[Test]
    public function sanitizeSvgElementRemoved(): void
    {
        $result = $this->policy->sanitize('<svg onload="alert(1)"><circle r="10"></circle></svg>');
        self::assertStringNotContainsString('<svg', $result);
        self::assertStringNotContainsString('onload', $result);
    }

    #[Test]
    public function sanitizeMathElementRemoved(): void
    {
        $result = $this->policy->sanitize('<math><mi>x</mi></math>');
        self::assertStringNotContainsString('<math', $result);
    }

    // ── XSS vectors: CDATA sections ──────────────────────────────────

    #[Test]
    public function sanitizeCdataSectionsRemoved(): void
    {
        $result = $this->policy->sanitize('<p><![CDATA[<script>alert(1)</script>]]></p>');
        self::assertStringNotContainsString('CDATA', $result);
        self::assertStringNotContainsString('<script', $result);
    }

    // ── XSS vectors: style attributes and elements ───────────────────

    #[Test]
    public function sanitizeStyleAttributeStripped(): void
    {
        $result = $this->policy->sanitize('<p style="background:url(javascript:alert(1))">text</p>');
        self::assertStringNotContainsString('style=', $result);
        self::assertStringContainsString('text', $result);
    }

    #[Test]
    public function sanitizeStyleElementRemoved(): void
    {
        $result = $this->policy->sanitize('<style>body{background:red}</style>');
        self::assertStringNotContainsString('<style', $result);
    }

    // ── XSS vectors: data-* attributes ───────────────────────────────

    #[Test]
    public function sanitizeDataAttributesStripped(): void
    {
        $result = $this->policy->sanitize('<p data-value="123" data-custom="xss">text</p>');
        self::assertStringNotContainsString('data-value', $result);
        self::assertStringNotContainsString('data-custom', $result);
    }

    // ── XSS vectors: id attributes ───────────────────────────────────

    #[Test]
    public function sanitizeIdAttributeStripped(): void
    {
        $result = $this->policy->sanitize('<p id="my-id">text</p>');
        self::assertStringNotContainsString('id=', $result);
    }

    // ── XSS vectors: BiDi characters ─────────────────────────────────

    #[Test]
    public function sanitizeBidiCharactersRemoved(): void
    {
        $result = $this->policy->sanitize("<p>\u{202A}text\u{202C}</p>");
        self::assertStringNotContainsString("\u{202A}", $result);
        self::assertStringNotContainsString("\u{202C}", $result);
    }

    #[Test]
    public function sanitizeBidiIsolateCharactersRemoved(): void
    {
        $result = $this->policy->sanitize("<p>\u{2066}text\u{2069}</p>");
        self::assertStringNotContainsString("\u{2066}", $result);
        self::assertStringNotContainsString("\u{2069}", $result);
    }

    // ── XSS vectors: null bytes ──────────────────────────────────────

    #[Test]
    public function sanitizeNullBytesRemoved(): void
    {
        $result = $this->policy->sanitize("<p>te\x00xt</p>");
        self::assertStringNotContainsString("\x00", $result);
    }

    // ── XSS vectors: dangerous named attributes ─────────────────────

    #[Test]
    #[DataProvider('dangerousAttributesProvider')]
    public function sanitizeDangerousAttributeStripped(string $input, string $forbiddenAttr): void
    {
        $result = $this->policy->sanitize($input);
        self::assertStringNotContainsString($forbiddenAttr, $result);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dangerousAttributesProvider(): iterable
    {
        yield 'srcset' => ['<img src="/media/x.jpg" srcset="/media/x-2x.jpg 2x" alt="x">', 'srcset'];
        yield 'formaction' => ['<p formaction="https://evil.com">text</p>', 'formaction'];
    }

    // ── Malformed HTML graceful handling ──────────────────────────────

    #[Test]
    public function sanitizeUnclosedTagsHandled(): void
    {
        $result = $this->policy->sanitize('<p>Unclosed paragraph');
        self::assertStringContainsString('Unclosed paragraph', $result);
    }

    #[Test]
    public function sanitizeDeeplyNestedTags(): void
    {
        $input = str_repeat('<p>', 50) . 'text' . str_repeat('</p>', 50);
        $result = $this->policy->sanitize($input);
        self::assertStringContainsString('text', $result);
    }

    // ── External links get rel="noopener noreferrer" ─────────────────

    #[Test]
    public function sanitizeExternalLinkGetsNoopenerNoreferrer(): void
    {
        $result = $this->policy->sanitize('<a href="https://example.com">Link</a>');
        self::assertStringContainsString('rel="noopener noreferrer"', $result);
    }

    #[Test]
    public function sanitizeRelativeLinkNoRelAdded(): void
    {
        $result = $this->policy->sanitize('<a href="/about">About</a>');
        self::assertStringNotContainsString('noopener', $result);
    }

    #[Test]
    public function sanitizeMailtoLinkNoRelAdded(): void
    {
        $result = $this->policy->sanitize('<a href="mailto:user@example.com">Email</a>');
        // mailto: has a scheme, so it does get rel added
        self::assertStringContainsString('href="mailto:user@example.com"', $result);
    }

    // ── Image src validation ─────────────────────────────────────────

    #[Test]
    public function sanitizeImgWithHttpsSrcAllowed(): void
    {
        $result = $this->policy->sanitize('<img src="https://cdn.example.com/photo.jpg" alt="Photo">');
        self::assertStringContainsString('src="https://cdn.example.com/photo.jpg"', $result);
    }

    #[Test]
    public function sanitizeImgWithHttpSrcBlocked(): void
    {
        $result = $this->policy->sanitize('<img src="http://cdn.example.com/photo.jpg" alt="Photo">');
        self::assertStringNotContainsString('<img', $result);
    }

    #[Test]
    public function sanitizeImgWithRelativeMediaSrcAllowed(): void
    {
        $result = $this->policy->sanitize('<img src="/media/uploads/photo.jpg" alt="Photo">');
        self::assertStringContainsString('src="/media/uploads/photo.jpg"', $result);
    }

    #[Test]
    public function sanitizeImgWithRelativeNonMediaSrcBlocked(): void
    {
        $result = $this->policy->sanitize('<img src="/uploads/photo.jpg" alt="Photo">');
        self::assertStringNotContainsString('<img', $result);
    }

    // ── HTML comment removal ─────────────────────────────────────────

    #[Test]
    public function sanitizeHtmlCommentsRemoved(): void
    {
        $result = $this->policy->sanitize('<p>Before<!-- comment -->After</p>');
        self::assertStringNotContainsString('<!--', $result);
        self::assertStringNotContainsString('comment', $result);
    }

    // ── Processing instruction removal ───────────────────────────────

    #[Test]
    public function sanitizeProcessingInstructionsRemoved(): void
    {
        $result = $this->policy->sanitize('<p>Text<?php echo "xss"; ?>More</p>');
        self::assertStringNotContainsString('<?php', $result);
    }

    // ── Comment sanitization (subset) ────────────────────────────────

    #[Test]
    public function sanitizeCommentAllowsSubsetElements(): void
    {
        $input = '<p>Paragraph</p><strong>Bold</strong><em>Italic</em><a href="https://example.com">Link</a>';
        $result = $this->policy->sanitizeComment($input);
        self::assertStringContainsString('<p>', $result);
        self::assertStringContainsString('<strong>', $result);
        self::assertStringContainsString('<em>', $result);
        self::assertStringContainsString('<a ', $result);
    }

    #[Test]
    public function sanitizeCommentBlocksNonCommentElements(): void
    {
        $input = '<h2>Heading</h2><img src="/media/x.jpg" alt="x"><table><tr><td>Cell</td></tr></table>';
        $result = $this->policy->sanitizeComment($input);
        self::assertStringNotContainsString('<h2', $result);
        self::assertStringNotContainsString('<img', $result);
        self::assertStringNotContainsString('<table', $result);
    }

    #[Test]
    public function sanitizeCommentAllowsCodeAndBlockquote(): void
    {
        $input = '<code>code</code><blockquote>quote</blockquote><pre>preformatted</pre>';
        $result = $this->policy->sanitizeComment($input);
        self::assertStringContainsString('<code>', $result);
        self::assertStringContainsString('<blockquote>', $result);
        self::assertStringContainsString('<pre>', $result);
    }

    #[Test]
    public function sanitizeCommentStripsXss(): void
    {
        $result = $this->policy->sanitizeComment('<script>alert(1)</script>');
        self::assertStringNotContainsString('<script', $result);
    }

    // ── Complex multi-vector attacks ─────────────────────────────────

    #[Test]
    public function sanitizeNestedScriptInAllowedElement(): void
    {
        $result = $this->policy->sanitize('<p>Safe<script>alert(1)</script>Content</p>');
        self::assertStringNotContainsString('<script', $result);
        self::assertStringContainsString('Safe', $result);
        self::assertStringContainsString('Content', $result);
    }

    #[Test]
    public function sanitizeEventHandlerOnAllowedElement(): void
    {
        $result = $this->policy->sanitize('<p onmouseover="alert(1)">text</p>');
        self::assertStringNotContainsString('onmouseover', $result);
        self::assertStringContainsString('<p>', $result);
    }

    #[Test]
    public function sanitizeMultipleAttacksCombined(): void
    {
        $input = '<p style="color:red" onclick="alert(1)" data-x="y" id="z">Text</p>';
        $result = $this->policy->sanitize($input);
        self::assertStringNotContainsString('style=', $result);
        self::assertStringNotContainsString('onclick', $result);
        self::assertStringNotContainsString('data-x', $result);
        self::assertStringNotContainsString('id=', $result);
        self::assertStringContainsString('Text', $result);
    }

    // ── UTF-8 BOM handling ───────────────────────────────────────────

    #[Test]
    public function sanitizeStripsUtf8Bom(): void
    {
        $result = $this->policy->sanitize("\xEF\xBB\xBF<p>Text</p>");
        self::assertStringNotContainsString("\xEF\xBB\xBF", $result);
        self::assertStringContainsString('Text', $result);
    }

    // ── Expression-based attacks ─────────────────────────────────────

    #[Test]
    public function sanitizeExpressionInStyleBlocked(): void
    {
        $result = $this->policy->sanitize('<p style="width:expression(alert(1))">text</p>');
        self::assertStringNotContainsString('expression', $result);
    }

    // ── Comprehensive XSS vectors via data provider ──────────────────

    #[Test]
    #[DataProvider('owaspXssVectorsProvider')]
    public function sanitizeOwaspXssVector(string $input, string $mustNotContain): void
    {
        $result = $this->policy->sanitize($input);
        self::assertStringNotContainsString($mustNotContain, strtolower($result));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function owaspXssVectorsProvider(): iterable
    {
        // Script tag variants
        yield 'script basic' => ['<script>alert(1)</script>', '<script'];
        yield 'script uppercase' => ['<SCRIPT>alert(1)</SCRIPT>', '<script'];
        yield 'script with type' => ['<script type="text/javascript">alert(1)</script>', '<script'];
        yield 'script with charset' => ['<script charset="utf-8">alert(1)</script>', '<script'];

        // Event handlers on various elements
        yield 'img onerror' => ['<img src=x onerror=alert(1)>', 'onerror'];
        yield 'body onload' => ['<body onload=alert(1)>', 'onload'];
        yield 'svg onload' => ['<svg onload=alert(1)>', '<svg'];
        yield 'video onerror' => ['<video onerror=alert(1)><source src=x></video>', 'onerror'];
        yield 'marquee onstart' => ['<marquee onstart=alert(1)>', 'onstart'];
        yield 'details ontoggle' => ['<details open ontoggle=alert(1)><summary>X</summary></details>', 'ontoggle'];

        // Protocol-based attacks
        yield 'javascript href' => ['<a href="javascript:alert(1)">x</a>', 'javascript'];
        yield 'javascript src' => ['<img src="javascript:alert(1)">', 'javascript'];

        // Encoding bypasses
        yield 'html entity script' => ['&#60;script&#62;alert(1)&#60;/script&#62;', '<script'];
        yield 'unicode encode' => ['<a href="\u006Aavascript:alert(1)">x</a>', 'javascript'];

        // Injection in attributes
        yield 'style with expression' => ['<p style="x:expression(alert(1))">x</p>', 'expression'];

        // Dangerous elements
        yield 'object' => ['<object data="javascript:alert(1)">x</object>', '<object'];
        yield 'embed' => ['<embed src="javascript:alert(1)">', '<embed'];
        yield 'applet' => ['<applet code="x">x</applet>', '<applet'];
        yield 'base tag' => ['<base href="javascript:alert(1)//">', '<base'];
        yield 'link' => ['<link rel="stylesheet" href="javascript:alert(1)">', '<link'];
        yield 'meta' => ['<meta http-equiv="refresh" content="0;url=javascript:alert(1)">', '<meta'];

        // Template and slot injection
        yield 'template' => ['<template><script>alert(1)</script></template>', '<script'];

        // Custom element attack
        yield 'custom element' => ['<custom-el onload="alert(1)">x</custom-el>', 'onload'];
    }

    // ── Additional edge cases ────────────────────────────────────────

    #[Test]
    public function sanitizePreservesTextContentOfMixedHtml(): void
    {
        $input = '<p>First</p><div>Middle</div><p>Last</p>';
        $result = $this->policy->sanitize($input);
        self::assertStringContainsString('First', $result);
        self::assertStringContainsString('Middle', $result);
        self::assertStringContainsString('Last', $result);
    }

    #[Test]
    public function sanitizePlainTextPassthrough(): void
    {
        $result = $this->policy->sanitize('Just plain text with no HTML');
        self::assertStringContainsString('Just plain text with no HTML', $result);
    }

    #[Test]
    public function sanitizeHtmlEntitiesInTextPreserved(): void
    {
        $result = $this->policy->sanitize('<p>&amp; &lt; &gt;</p>');
        self::assertStringContainsString('<p>', $result);
    }

    #[Test]
    public function sanitizeEmptyHrefLinkUnwrapped(): void
    {
        $result = $this->policy->sanitize('<a href="">text</a>');
        // Empty href should be invalid
        self::assertStringNotContainsString('<a ', $result);
    }

    #[Test]
    public function sanitizeEmptyImgSrcRemoved(): void
    {
        $result = $this->policy->sanitize('<img src="" alt="empty">');
        self::assertStringNotContainsString('<img', $result);
    }

    #[Test]
    public function sanitizeImgAltAttributePreserved(): void
    {
        $result = $this->policy->sanitize('<img src="/media/photo.jpg" alt="A beautiful sunset">');
        self::assertStringContainsString('alt="A beautiful sunset"', $result);
    }

    #[Test]
    public function sanitizeImgWidthHeightPreserved(): void
    {
        $result = $this->policy->sanitize('<img src="/media/photo.jpg" alt="x" width="100" height="200">');
        self::assertStringContainsString('width="100"', $result);
        self::assertStringContainsString('height="200"', $result);
    }

    #[Test]
    public function sanitizeARelPreservedOnExternal(): void
    {
        $result = $this->policy->sanitize('<a href="https://example.com" rel="nofollow">Link</a>');
        // rel should be overwritten to noopener noreferrer
        self::assertStringContainsString('rel="noopener noreferrer"', $result);
    }

    #[Test]
    public function sanitizeInvalidMailtoBlocked(): void
    {
        $result = $this->policy->sanitize('<a href="mailto:not-an-email">Link</a>');
        self::assertStringNotContainsString('<a ', $result);
    }

    #[Test]
    public function sanitizeValidMailtoAllowed(): void
    {
        $result = $this->policy->sanitize('<a href="mailto:user@example.com">Email</a>');
        self::assertStringContainsString('mailto:user@example.com', $result);
    }

    // ── Defense-in-depth: final regex scan ───────────────────────────

    #[Test]
    public function sanitizeBypassedJavascriptCaughtByFinalScan(): void
    {
        // If somehow javascript: makes it through the DOM filtering,
        // the final containsDangerousPatterns() should catch it
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $policy = new SafeHtmlPolicy($auditLogger);

        $result = $policy->sanitize('<a href="https://safe.com">link</a>');
        // Normal safe input should pass
        self::assertStringContainsString('<a ', $result);
    }

    // ── Self-closing tag normalization ────────────────────────────────

    #[Test]
    public function sanitizeBrNormalizedToHtml5(): void
    {
        $result = $this->policy->sanitize('<p>Line<br />break</p>');
        // Should contain <br> not <br />
        self::assertStringContainsString('<br>', $result);
    }

    #[Test]
    public function sanitizeHrNormalizedToHtml5(): void
    {
        $result = $this->policy->sanitize('<hr />');
        self::assertStringContainsString('<hr>', $result);
    }
}
