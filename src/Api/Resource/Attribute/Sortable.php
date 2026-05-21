<?php

declare(strict_types=1);

namespace Pulsar\Api\Resource\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a field as sortable in API queries.
 *
 * Fields with this attribute can be used in `?sort=field` or `?sort=-field`
 * query parameters. The default direction is ascending.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
#[Api(since: '1.0.0')]
final readonly class Sortable
{
    /**
     * @param string $defaultDirection Default sort direction ('asc' or 'desc')
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $defaultDirection = 'asc',
    ) {}
}
