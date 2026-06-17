<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Editor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Content\MarkdownRendererInterface;
use Pulsar\Extension\Forum\Editor\EditorFormat;
use Pulsar\Extension\Forum\Editor\RichTextProcessor;

use function str_contains;
use function strtolower;

#[CoversClass(RichTextProcessor::class)]
final class RichTextProcessorTest extends TestCase
{
    private function processor(string $markdownOutput = ''): RichTextProcessor
    {
        $renderer = $this->createStub(MarkdownRendererInterface::class);
        $renderer->method('render')->willReturn($markdownOutput);

        return new RichTextProcessor($renderer);
    }

    private function html(string $input): string
    {
        return strtolower($this->processor()->process($input, EditorFormat::Html)->html);
    }

    #[Test]
    public function stripsEventHandlerAttributesOnAllowedTags(): void
    {
        // strip_tags kept attributes on allowed tags — the stored-XSS bug.
        $html = $this->html('<a href="/x" onclick="alert(1)" onmouseover="evil()">link</a>');

        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('onmouseover', $html);
        self::assertStringContainsString('link', $html);
    }

    #[Test]
    public function blocksJavascriptHrefScheme(): void
    {
        $html = $this->html('<a href="javascript:alert(document.cookie)">click</a>');

        self::assertStringNotContainsString('javascript:', $html);
    }

    #[Test]
    public function stripsImgOnerrorHandler(): void
    {
        $html = $this->html('<img src="/img.png" onerror="alert(1)">');

        self::assertStringNotContainsString('onerror', $html);
    }

    #[Test]
    public function removesScriptEntirely(): void
    {
        $html = $this->html('<p>hello</p><script>steal()</script>');

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('steal()', $html);
        self::assertStringContainsString('hello', $html);
    }

    #[Test]
    public function blocksHttpImageButKeepsRelative(): void
    {
        // http (mixed-content) image is dropped; relative same-origin image kept.
        self::assertStringNotContainsString('http://evil', $this->html('<img src="http://evil.example/x.png">'));
        self::assertStringContainsString('/safe.png', $this->html('<img src="/safe.png">'));
    }

    #[Test]
    public function preservesAllowedFormatting(): void
    {
        $html = $this->html('<strong>bold</strong> and <em>italic</em> and <code>x</code>');

        self::assertStringContainsString('<strong>', $html);
        self::assertStringContainsString('<em>', $html);
        self::assertStringContainsString('<code>', $html);
    }

    #[Test]
    public function markdownOutputIsSanitized(): void
    {
        // A renderer producing malicious HTML must still be sanitized.
        $result = $this->processor('<p onclick="x()">para</p><script>bad()</script>')
            ->process('ignored-source', EditorFormat::Markdown);

        self::assertStringNotContainsString('onclick', $result->html);
        self::assertStringNotContainsString('<script', $result->html);
        self::assertStringContainsString('para', $result->html);
    }

    #[Test]
    public function plainTextIsFullyEscaped(): void
    {
        $result = $this->processor()->process('<b>x</b> & "y"', EditorFormat::PlainText);

        self::assertStringNotContainsString('<b>', $result->html);
        self::assertStringContainsString('&lt;b&gt;', $result->html);
    }

    #[Test]
    public function plainTextOfSanitizedHtmlHasNoMarkup(): void
    {
        $result = $this->processor()->process('<p>visible</p><img src="/x" onerror="alert(1)">', EditorFormat::Html);

        self::assertFalse(str_contains($result->plainText, '<'), 'plain text must not contain markup');
        self::assertStringContainsString('visible', $result->plainText);
    }
}
