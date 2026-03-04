<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Content;

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
    public function emptyStringReturnsEmpty(): void
    {
        self::assertSame('', $this->policy->sanitize(''));
    }

    #[Test]
    public function allowedElementsArePreserved(): void
    {
        $input = '<p>Hello <strong>world</strong></p>';
        $result = $this->policy->sanitize($input);

        self::assertStringContainsString('<p>', $result);
        self::assertStringContainsString('<strong>world</strong>', $result);
    }

    #[Test]
    public function scriptTagIsRemoved(): void
    {
        $input = '<p>Safe</p><script>alert("xss")</script>';
        $result = $this->policy->sanitize($input);

        self::assertStringNotContainsString('<script>', $result);
        self::assertStringNotContainsString('alert', $result);
    }

    #[Test]
    public function eventHandlersAreStripped(): void
    {
        $input = '<p onclick="alert(1)">Click</p>';
        $result = $this->policy->sanitize($input);

        self::assertStringNotContainsString('onclick', $result);
        self::assertStringContainsString('Click', $result);
    }

    #[Test]
    public function dataAttributesAreStripped(): void
    {
        $input = '<p data-custom="value">Text</p>';
        $result = $this->policy->sanitize($input);

        self::assertStringNotContainsString('data-custom', $result);
        self::assertStringContainsString('Text', $result);
    }

    #[Test]
    public function linkWithHttpSchemeIsAllowed(): void
    {
        $input = '<a href="https://example.com">Link</a>';
        $result = $this->policy->sanitize($input);

        self::assertStringContainsString('href="https://example.com"', $result);
        self::assertStringContainsString('rel="noopener noreferrer nofollow"', $result);
    }

    #[Test]
    public function linkWithJavascriptSchemeIsRemoved(): void
    {
        $input = '<a href="javascript:alert(1)">Evil</a>';
        $result = $this->policy->sanitize($input);

        self::assertStringNotContainsString('javascript:', $result);
    }

    #[Test]
    public function imgWithHttpsSrcIsAllowed(): void
    {
        $input = '<img src="https://example.com/pic.jpg" alt="Photo">';
        $result = $this->policy->sanitize($input);

        self::assertStringContainsString('src="https://example.com/pic.jpg"', $result);
        self::assertStringContainsString('alt="Photo"', $result);
    }

    #[Test]
    public function imgWithHttpSrcIsRemoved(): void
    {
        $input = '<img src="http://insecure.com/pic.jpg" alt="Bad">';
        $result = $this->policy->sanitize($input);

        self::assertStringNotContainsString('src="http://', $result);
    }

    #[Test]
    public function iframeIsRemoved(): void
    {
        $input = '<p>Text</p><iframe src="https://evil.com"></iframe>';
        $result = $this->policy->sanitize($input);

        self::assertStringNotContainsString('<iframe', $result);
    }

    #[Test]
    public function svgIsRemoved(): void
    {
        $input = '<svg onload="alert(1)"><circle r="50"/></svg>';
        $result = $this->policy->sanitize($input);

        self::assertStringNotContainsString('<svg', $result);
    }

    #[Test]
    public function codeClassLanguagePrefixIsAllowed(): void
    {
        $input = '<code class="language-php">$x = 1;</code>';
        $result = $this->policy->sanitize($input);

        self::assertStringContainsString('class="language-php"', $result);
    }

    #[Test]
    public function codeClassWithoutLanguagePrefixIsRemoved(): void
    {
        $input = '<code class="malicious">code</code>';
        $result = $this->policy->sanitize($input);

        self::assertStringNotContainsString('class="malicious"', $result);
        self::assertStringContainsString('<code>code</code>', $result);
    }

    #[Test]
    public function nullBytesAreStripped(): void
    {
        $input = "<p>Hello\x00World</p>";
        $result = $this->policy->sanitize($input);

        self::assertStringNotContainsString("\x00", $result);
        self::assertStringContainsString('HelloWorld', $result);
    }

    #[Test]
    public function utf8BomIsStripped(): void
    {
        $input = "\xEF\xBB\xBF<p>BOM content</p>";
        $result = $this->policy->sanitize($input);

        self::assertStringNotContainsString("\xEF\xBB\xBF", $result);
        self::assertStringContainsString('BOM content', $result);
    }

    #[Test]
    public function tableElementsAreAllowed(): void
    {
        $input = '<table><thead><tr><th scope="col">Header</th></tr></thead><tbody><tr><td>Cell</td></tr></tbody></table>';
        $result = $this->policy->sanitize($input);

        self::assertStringContainsString('<table>', $result);
        self::assertStringContainsString('<th', $result);
        self::assertStringContainsString('<td>', $result);
    }

    #[Test]
    #[DataProvider('disallowedElementProvider')]
    public function disallowedElementsAreUnwrapped(string $tag): void
    {
        $input = "<$tag>content</$tag>";
        $result = $this->policy->sanitize($input);

        self::assertStringNotContainsString("<$tag>", $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function disallowedElementProvider(): iterable
    {
        yield 'span' => ['span'];
        yield 'div (non-wrapper)' => ['section'];
        yield 'form' => ['form'];
        yield 'input' => ['input'];
        yield 'style' => ['style'];
    }

    #[Test]
    public function cdataMarkersAreStripped(): void
    {
        $input = '<p><![CDATA[injected]]></p>';
        $result = $this->policy->sanitize($input);

        self::assertStringNotContainsString('CDATA', $result);
    }

    #[Test]
    public function relativeLinksAreAllowed(): void
    {
        $input = '<a href="/forum/thread/1">Thread</a>';
        $result = $this->policy->sanitize($input);

        self::assertStringContainsString('href="/forum/thread/1"', $result);
        // relative links should not get noopener/noreferrer
        self::assertStringNotContainsString('noopener', $result);
    }

    #[Test]
    public function mailtoLinksAreAllowed(): void
    {
        $input = '<a href="mailto:user@example.com">Email</a>';
        $result = $this->policy->sanitize($input);

        self::assertStringContainsString('href="mailto:user@example.com"', $result);
    }
}
