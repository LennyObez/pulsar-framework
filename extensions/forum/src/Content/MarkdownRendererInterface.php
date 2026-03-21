<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Content;

use Pulsar\Api\Api;

/**
 * Contract for Markdown-to-HTML rendering in the forum.
 *
 * Output MUST be passed through ForumBodyPolicy before storage/display.
 * @api
 */
#[Api(since: '1.0.0')]
interface MarkdownRendererInterface
{
    /**
     * Convert Markdown source to HTML.
     *
     * @param string $markdown Raw Markdown input
     * @return string Rendered HTML (must be sanitized before display)
     */
    public function render(string $markdown): string;
}
