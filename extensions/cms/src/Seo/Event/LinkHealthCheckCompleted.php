<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Seo\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a link health check run completes.
 */
#[Api(since: '1.0.0')]
final readonly class LinkHealthCheckCompleted
{
    public function __construct(
        public int $totalChecked,
        public int $brokenCount,
    ) {}
}
