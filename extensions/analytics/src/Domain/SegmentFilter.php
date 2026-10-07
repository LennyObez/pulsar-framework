<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;

/**
 * A single filter condition within an audience segment.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SegmentFilter
{
    /**
     * @param SegmentDimension $dimension The dimension to filter on
     * @param SegmentOperator $operator Comparison operator
     * @param string $value Value to compare against
     */
    public function __construct(
        public SegmentDimension $dimension,
        public SegmentOperator $operator,
        public string $value,
    ) {}
}
