<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a user submits a report against a thread or post.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ReportSubmitted
{
    /**
     * @param string $targetType 'thread' or 'post'
     */
    public function __construct(
        public string $reportId,
        public string $targetType,
        public string $targetId,
        public string $reporterId,
        public string $reason,
        public ?string $tenantId = null,
    ) {}
}
