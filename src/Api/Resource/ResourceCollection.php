<?php

declare(strict_types=1);

namespace Pulsar\Api\Resource;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Api\Security\ClearanceSnapshot;

use function array_map;
use function count;

/**
 * Wraps a collection of API resources with pagination metadata.
 *
 * Provides uniform serialization for resource lists, including pagination
 * information (total count, page details, cursors) when available.
 */
#[Api(since: '1.0.0')]
final readonly class ResourceCollection
{
    /**
     * @param list<AbstractApiResource> $items The resource items in this page
     * @param int|null $total Total count of items across all pages (null if unknown)
     * @param array<string, mixed> $paginationMeta Pagination metadata (page, per_page, cursors, etc.)
     * @param string $resourceType The resource type identifier
     */
    public function __construct(
        public array $items,
        public ?int $total = null,
        public array $paginationMeta = [],
        public string $resourceType = '',
    ) {}

    /**
     * Serialize the collection to an array.
     *
     * @param ClearanceSnapshot|null $clearance Requester's clearance
     * @param list<string>|null $requestedFields Sparse fieldset
     * @param array<string, RedactionRule> $redactionRules Field name => redaction rule
     * @param bool $includeRedactionMeta Whether to include `_meta.redactions` (debug/audit only)
     *
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(
        ?ClearanceSnapshot $clearance = null,
        ?array $requestedFields = null,
        array $redactionRules = [],
        bool $includeRedactionMeta = false,
    ): array {
        $data = array_map(
            static fn(AbstractApiResource $resource): array => $resource->toArray(
                $clearance,
                $requestedFields,
                $redactionRules,
                $includeRedactionMeta,
            ),
            $this->items,
        );

        /** @var array<string, mixed> $meta */
        $meta = [];

        if ($this->total !== null) {
            $meta['total'] = $this->total;
        }

        if ($this->paginationMeta !== []) {
            $meta = $meta + $this->paginationMeta;
        }

        $result = ['data' => $data];

        if ($meta !== []) {
            $result['meta'] = $meta;
        }

        return $result;
    }

    /**
     * Get the number of items in this page.
     */
    #[NoDiscard]
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Check if the collection is empty.
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
