<?php

declare(strict_types=1);

namespace Pulsar\Api\Pagination;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * HATEOAS pagination links (first, last, next, prev).
 */
#[Api(since: '1.0.0')]
final readonly class PaginationLinks
{
    public function __construct(
        public ?string $first = null,
        public ?string $last = null,
        public ?string $next = null,
        public ?string $prev = null,
    ) {}

    /**
     * Serialize to array, omitting null links.
     *
     * @return array<string, string>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        $links = [];

        if ($this->first !== null) {
            $links['first'] = $this->first;
        }

        if ($this->last !== null) {
            $links['last'] = $this->last;
        }

        if ($this->next !== null) {
            $links['next'] = $this->next;
        }

        if ($this->prev !== null) {
            $links['prev'] = $this->prev;
        }

        return $links;
    }
}
