<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use Pulsar\Api\Api;

/**
 * Result of a cascading path recomputation operation.
 *
 * @psalm-api Returned by PathComputer::recompute() to user-land callers.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PathRecomputeResult
{
    /**
     * @param int $descendantsUpdated Number of descendant translations updated
     * @param int $redirectsCreated Number of redirect records created for changed paths
     */
    public function __construct(
        public int $descendantsUpdated,
        public int $redirectsCreated,
    ) {}
}
