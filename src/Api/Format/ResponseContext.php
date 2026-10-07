<?php

declare(strict_types=1);

namespace Pulsar\Api\Format;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationLinks;
use Pulsar\Api\Pagination\PaginationMeta;

/**
 * Value object carrying rendering context for response formatters.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ResponseContext
{
    /**
     * @param string $resourceType The resource type identifier
     * @param list<string>|null $requestedFields Sparse fieldset selection
     * @param PaginationMeta|null $paginationMeta Pagination metadata
     * @param PaginationLinks|null $paginationLinks Pagination navigation links
     * @param array<string, mixed> $meta Additional response metadata
     */
    public function __construct(
        public string $resourceType = '',
        public ?array $requestedFields = null,
        public ?PaginationMeta $paginationMeta = null,
        public ?PaginationLinks $paginationLinks = null,
        public array $meta = [],
    ) {}
}
