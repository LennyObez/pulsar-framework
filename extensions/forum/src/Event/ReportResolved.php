<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Event;

use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Domain\ReportStatus;

/**
 * Dispatched when a moderator resolves a report (actioned or dismissed).
 */
#[Api(since: '1.0.0')]
final readonly class ReportResolved
{
    /**
     * @param string $targetType 'thread' or 'post'
     */
    public function __construct(
        public string $reportId,
        public string $targetType,
        public string $targetId,
        public string $moderatorId,
        public ReportStatus $resolution,
        public ?string $tenantId = null,
    ) {}
}
