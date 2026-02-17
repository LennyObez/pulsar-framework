<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Editor;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Content\MarkdownRendererInterface;
use Pulsar\Extension\Forum\Editor\EditorFormat;
use Pulsar\Extension\Forum\Editor\RichTextProcessor;

use function strlen;

final class RichTextProcessorTest extends TestCase
{
    private RichTextProcessor $processor;
    private MarkdownRendererInterface&Stub $markdownRenderer;

    protected function setUp(): void
    {
        $this->markdownRenderer = $this->createStub(MarkdownRendererInterface::class);
        $this->processor = new RichTextProcessor($this->markdownRenderer);
    }

    #[Test]
    public function processPlainTextEscapesHtml(): void
    {
        $result = $this->processor->process('<script>alert("xss")</script>', EditorFormat::PlainText);

        self::assertStringNotContainsString('<script>', $result->html);
        self::assertStringContainsString('&lt;script&gt;', $result->html);
        self::assertSame(EditorFormat::PlainText, $result->sourceFormat);
    }

    #[Test]
    public function processPlainTextConvertsNewlines(): void
    {
        $result = $this->processor->process("Line 1\nLine 2", EditorFormat::PlainText);

        self::assertStringContainsString('<br', $result->html);
    }

    #[Test]
    public function processMarkdownRendersAndSanitizes(): void
    {
        $this->markdownRenderer->method('render')
            ->willReturn('<p>Hello <strong>world</strong></p><script>evil()</script>');

        $result = $this->processor->process('Hello **world**', EditorFormat::Markdown);

        self::assertStringContainsString('<p>Hello <strong>world</strong></p>', $result->html);
        self::assertStringNotContainsString('<script>', $result->html);
    }

    #[Test]
    public function processHtmlSanitizesUnsafeTags(): void
    {
        $html = '<p>Safe</p><script>alert(1)</script><iframe src="evil"></iframe>';

        $result = $this->processor->process($html, EditorFormat::Html);

        self::assertStringContainsString('<p>Safe</p>', $result->html);
        self::assertStringNotContainsString('<script>', $result->html);
        self::assertStringNotContainsString('<iframe>', $result->html);
    }

    #[Test]
    public function processGeneratesPlainTextFromHtml(): void
    {
        $this->markdownRenderer->method('render')
            ->willReturn('<p>Hello <strong>world</strong></p>');

        $result = $this->processor->process('Hello **world**', EditorFormat::Markdown);

        self::assertSame('Hello world', $result->plainText);
    }

    #[Test]
    public function processGeneratesPreviewUnder200Chars(): void
    {
        $shortText = 'Short content';

        $result = $this->processor->process($shortText, EditorFormat::PlainText);

        self::assertSame($shortText, $result->preview);
    }

    #[Test]
    public function processGeneratesPreviewTruncatedOver200Chars(): void
    {
        $longText = str_repeat('This is a long sentence. ', 20);

        $result = $this->processor->process($longText, EditorFormat::PlainText);

        self::assertStringEndsWith('...', $result->preview);
        self::assertLessThanOrEqual(203, strlen($result->preview)); // 200 + "..."
    }

    #[Test]
    public function processRejectsContentExceedingMaxLength(): void
    {
        $oversized = str_repeat('x', 100_001);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum length');

        $this->processor->process($oversized, EditorFormat::PlainText);
    }

    #[Test]
    public function processTrimsWhitespace(): void
    {
        $result = $this->processor->process("  hello  \n", EditorFormat::PlainText);

        self::assertStringContainsString('hello', $result->plainText);
    }

    #[Test]
    public function editorFormatEnumHasExpectedCases(): void
    {
        self::assertSame('markdown', EditorFormat::Markdown->value);
        self::assertSame('html', EditorFormat::Html->value);
        self::assertSame('plaintext', EditorFormat::PlainText->value);
        self::assertCount(3, EditorFormat::cases());
    }
}
