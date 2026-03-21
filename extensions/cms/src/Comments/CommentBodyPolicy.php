<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Comments;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;

/**
 * Strict HTML sanitization policy for comment bodies.
 *
 * Delegates to SafeHtmlPolicy::sanitizeComment() which uses the restricted
 * comment element subset: p, br, strong, em, a(href), code, blockquote, pre.
 *
 * @psalm-api Public service resolved from the DI container by CommentService;
 *            not instantiated by name.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CommentBodyPolicy
{
    public function __construct(
        private SafeHtmlPolicy $safeHtmlPolicy,
    ) {}

    /**
     * Sanitize a comment body to safe HTML using the strict comment subset.
     */
    public function sanitize(string $body): string
    {
        return $this->safeHtmlPolicy->sanitizeComment($body);
    }
}
