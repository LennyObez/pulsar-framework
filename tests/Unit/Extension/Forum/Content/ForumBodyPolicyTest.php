<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Content\ForumBodyPolicy;

#[CoversClass(ForumBodyPolicy::class)]
final class ForumBodyPolicyTest extends TestCase
{
    private ForumBodyPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ForumBodyPolicy();
    }

    #[Test]
    public function sanitizeReturnsEmptyStringForEmptyInput(): void
    {
        self::assertSame('', $this->policy->sanitize(''));
    }

    #[Test]
    public function sanitizePreservesAllowedElements(): void
    {
        $html = '<p>Hello <strong>world</strong> <em>test</em></p>';
        $result = $this->policy->sanitize($html);

        self::assertStringContainsString('<p>', $result);
        self::assertStringContainsString('<strong>world</strong>', $result);
        self::assertStringContainsString('<em>test</em>', $result);
    }

    #[Test]
    public function sanitizePreservesCodeBlocks(): void
    {
        $html = '<pre><code class="language-php">echo "hello";</code></pre>';
        $result = $this->policy->sanitize($html);

        self::assertStringContainsString('<pre>', $result);
        self::assertStringContainsString('<code class="language-php">', $result);
    }

    #[Test]
    public function sanitizePreservesLinks(): void
    {
        $html = '<a href="https://example.com" title="Example">Link</a>';
        $result = $this->policy->sanitize($html);

        self::assertStringContainsString('href="https://example.com"', $result);
        self::assertStringContainsString('title="Example"', $result);
        self::assertStringContainsString('rel="noopener noreferrer nofollow"', $result);
    }

    #[Test]
    public function sanitizePreservesImages(): void
    {
        $html = '<img src="https://example.com/img.png" alt="Image" width="100" height="50">';
        $result = $this->policy->sanitize($html);

        self::assertStringContainsString('src="https://example.com/img.png"', $result);
        self::assertStringContainsString('alt="Image"', $result);
    }

    #[Test]
    public function sanitizePreservesTableElements(): void
    {
        $html = '<table><thead><tr><th scope="col">Header</th></tr></thead><tbody><tr><td>Cell</td></tr></tbody></table>';
        $result = $this->policy->sanitize($html);

        self::assertStringContainsString('<table>', $result);
        self::assertStringContainsString('<td>', $result);
    }

    #[Test]
    public function sanitizePreservesListElements(): void
    {
        $html = '<ul><li>Item 1</li><li>Item 2</li></ul>';
        $result = $this->policy->sanitize($html);

        self::assertStringContainsString('<ul>', $result);
        self::assertStringContainsString('<li>Item 1</li>', $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function xssVectorProvider(): iterable
    {
        yield 'script tag' => ['<script>alert("xss")</script>'];
        yield 'img onerror' => ['<img src=x onerror="alert(1)">'];
        yield 'svg' => ['<svg onload="alert(1)"><circle></circle></svg>'];
        yield 'iframe' => ['<iframe src="https://evil.com"></iframe>'];
        yield 'object tag' => ['<object data="evil.swf"></object>'];
        yield 'embed tag' => ['<embed src="evil.swf">'];
        yield 'null bytes' => ["<scr\x00ipt>alert(1)</script>"];
    }

    #[Test]
    #[DataProvider('xssVectorProvider')]
    public function sanitizeRemovesXssVectors(string $xssHtml): void
    {
        $result = $this->policy->sanitize($xssHtml);

        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('onerror', $result);
        self::assertStringNotContainsString('onload', $result);
        self::assertStringNotContainsString('<svg', $result);
        self::assertStringNotContainsString('<iframe', $result);
        self::assertStringNotContainsString('<object', $result);
        self::assertStringNotContainsString('<embed', $result);
    }

    #[Test]
    public function sanitizeStripsEventHandlerAttributes(): void
    {
        $html = '<p onmouseover="evil()" onmouseout="evil2()">Text</p>';
        $result = $this->policy->sanitize($html);

        self::assertStringNotContainsString('onmouseover', $result);
        self::assertStringNotContainsString('onmouseout', $result);
        self::assertStringContainsString('Text', $result);
    }

    #[Test]
    public function sanitizeStripsDataAttributes(): void
    {
        $html = '<p data-custom="value" data-evil="payload">Text</p>';
        $result = $this->policy->sanitize($html);

        self::assertStringNotContainsString('data-custom', $result);
        self::assertStringNotContainsString('data-evil', $result);
    }

    #[Test]
    public function sanitizeStripsDisallowedAttributesOnAllowedElements(): void
    {
        $html = '<p id="test" class="bad" style="color:red">Text</p>';
        $result = $this->policy->sanitize($html);

        self::assertStringNotContainsString('id=', $result);
        self::assertStringNotContainsString('class=', $result);
        self::assertStringNotContainsString('style=', $result);
    }

    #[Test]
    public function sanitizeValidatesCodeClassAttribute(): void
    {
        $validHtml = '<code class="language-php">code</code>';
        $invalidHtml = '<code class="evil-class">code</code>';

        $validResult = $this->policy->sanitize($validHtml);
        $invalidResult = $this->policy->sanitize($invalidHtml);

        self::assertStringContainsString('class="language-php"', $validResult);
        self::assertStringNotContainsString('class=', $invalidResult);
    }

    #[Test]
    public function sanitizeRemovesNullBytes(): void
    {
        $html = "<p>Hello\x00World</p>";
        $result = $this->policy->sanitize($html);

        self::assertStringNotContainsString("\x00", $result);
        self::assertStringContainsString('HelloWorld', $result);
    }

    #[Test]
    public function sanitizeRemovesBidiControlCharacters(): void
    {
        $html = "<p>Hello\u{202A}World\u{202E}</p>";
        $result = $this->policy->sanitize($html);

        self::assertStringNotContainsString("\u{202A}", $result);
        self::assertStringNotContainsString("\u{202E}", $result);
    }

    #[Test]
    public function sanitizeStripsUtf8Bom(): void
    {
        $html = "\xEF\xBB\xBF<p>Content</p>";
        $result = $this->policy->sanitize($html);

        self::assertStringNotContainsString("\xEF\xBB\xBF", $result);
        self::assertStringContainsString('Content', $result);
    }

    #[Test]
    public function sanitizeUnwrapsDisallowedElementsPreservingChildren(): void
    {
        $html = '<div><span>Text inside span</span></div>';
        $result = $this->policy->sanitize($html);

        self::assertStringContainsString('Text inside span', $result);
        self::assertStringNotContainsString('<span', $result);
    }

    #[Test]
    public function sanitizeRemovesImagesWithNonHttpsSrc(): void
    {
        $html = '<img src="http://example.com/img.jpg" alt="test">';
        $result = $this->policy->sanitize($html);

        self::assertStringNotContainsString('<img', $result);
    }

    #[Test]
    public function sanitizeAddsRelToExternalLinks(): void
    {
        $html = '<a href="https://external.com">External</a>';
        $result = $this->policy->sanitize($html);

        self::assertStringContainsString('rel="noopener noreferrer nofollow"', $result);
    }

    #[Test]
    public function sanitizeAllowsMailtoLinks(): void
    {
        $html = '<a href="mailto:test@example.com">Email</a>';
        $result = $this->policy->sanitize($html);

        self::assertStringContainsString('mailto:test@example.com', $result);
    }

    #[Test]
    public function sanitizeAllowsRelativeLinks(): void
    {
        $html = '<a href="/forum/thread/123">Thread</a>';
        $result = $this->policy->sanitize($html);

        self::assertStringContainsString('href="/forum/thread/123"', $result);
    }

    #[Test]
    public function sanitizePreservesDelElement(): void
    {
        $html = '<del>Deleted text</del>';
        $result = $this->policy->sanitize($html);

        self::assertStringContainsString('<del>Deleted text</del>', $result);
    }

    #[Test]
    public function sanitizePreservesBrElement(): void
    {
        $html = '<p>Line 1<br>Line 2</p>';
        $result = $this->policy->sanitize($html);

        self::assertStringContainsString('<br>', $result);
    }
}
