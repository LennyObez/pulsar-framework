<?php

declare(strict_types=1);

namespace Pulsar\Pagination;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Single entry in a paginator's link array.
 *
 * Describes a numbered page, a previous/next arrow, or an ellipsis gap.
 */
#[Api(since: '1.0.0')]
final readonly class PageLink
{
    public function __construct(
        /** Page number this link targets (ignored for ellipsis). */
        public int $page,
        /** Display label (number, arrow entity, or "..."). */
        public string $label,
        /** Whether this link is the currently active page. */
        public bool $isActive,
        /** Whether this link should be rendered as disabled. */
        public bool $isDisabled,
        /** Whether this is an ellipsis placeholder. */
        public bool $isEllipsis = false,
    ) {}

    /**
     * Create an ellipsis placeholder link.
     */
    #[NoDiscard]
    public static function ellipsis(): self
    {
        return new self(0, '...', false, true, true);
    }
}
