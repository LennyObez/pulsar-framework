<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Editor;

use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Content\MarkdownRendererInterface;

use function htmlspecialchars;
use function nl2br;
use function strip_tags;
use function strlen;
use function trim;

use const ENT_QUOTES;

/**
 * Processes rich text input from the forum editor.
 *
 * Handles Markdown-to-HTML conversion, sanitization, and preview
 * generation. Works with the MarkdownRenderer for the actual
 * Markdown parsing, adding security sanitization on top.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RichTextProcessor
{
    /**
     * Maximum content length in bytes.
     */
    private const int MAX_CONTENT_LENGTH = 100_000;

    /**
     * Allowed HTML tags after sanitization.
     */
    private const string ALLOWED_TAGS = '<p><br><strong><em><a><code><pre><blockquote><ul><ol><li><h1><h2><h3><h4><h5><h6><img><hr><table><thead><tbody><tr><th><td><del><sup><sub><details><summary>';

    public function __construct(
        private MarkdownRendererInterface $markdownRenderer,
    ) {}

    /**
     * Process editor input into safe HTML output.
     *
     * @throws InvalidArgumentException If content exceeds maximum length
     */
    public function process(string $content, EditorFormat $format): RichTextResult
    {
        $content = trim($content);

        if (strlen($content) > self::MAX_CONTENT_LENGTH) {
            throw new InvalidArgumentException(
                'Content exceeds maximum length of ' . self::MAX_CONTENT_LENGTH . ' bytes',
            );
        }

        $html = match ($format) {
            EditorFormat::Markdown => $this->processMarkdown($content),
            EditorFormat::Html => $this->sanitizeHtml($content),
            EditorFormat::PlainText => $this->processPlainText($content),
        };

        $plainText = strip_tags($html);
        $preview = strlen($plainText) > 200
            ? substr($plainText, 0, 200) . '...'
            : $plainText;

        return new RichTextResult(
            html: $html,
            plainText: $plainText,
            preview: $preview,
            sourceFormat: $format,
        );
    }

    private function processMarkdown(string $markdown): string
    {
        $html = $this->markdownRenderer->render($markdown);

        return $this->sanitizeHtml($html);
    }

    private function sanitizeHtml(string $html): string
    {
        return strip_tags($html, self::ALLOWED_TAGS);
    }

    private function processPlainText(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        return nl2br($escaped);
    }
}
