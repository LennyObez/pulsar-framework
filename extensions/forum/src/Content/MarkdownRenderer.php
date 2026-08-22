<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Content;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use Pulsar\Api\Api;

/**
 * Markdown-to-HTML renderer for forum posts and thread bodies.
 *
 * Wraps league/commonmark with GitHub Flavored Markdown (GFM) support
 * including tables, autolinks, strikethrough, and fenced code blocks.
 * Output MUST be passed through ForumBodyPolicy before storage/display.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MarkdownRenderer implements MarkdownRendererInterface
{
    private MarkdownConverter $converter;

    /**
     * @param int $maxNestingLevel Maximum nesting depth for block elements
     */
    public function __construct(
        int $maxNestingLevel = 10,
    ) {
        $config = [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => $maxNestingLevel,
        ];

        $environment = new Environment($config);
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new AutolinkExtension());
        $environment->addExtension(new StrikethroughExtension());

        $this->converter = new MarkdownConverter($environment);
    }

    /**
     * Convert Markdown source to HTML.
     *
     * @param string $markdown Raw Markdown input
     * @return string Rendered HTML (must be sanitized before display)
     */
    public function render(string $markdown): string
    {
        if ($markdown === '') {
            return '';
        }

        return trim($this->converter->convert($markdown)->getContent());
    }
}
