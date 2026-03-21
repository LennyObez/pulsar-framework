<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Domain;

use Pulsar\Api\Api;

/**
 * A single SLA escalation rule: triggers an action when elapsed
 * time exceeds the threshold.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class EscalationRule
{
    /**
     * @param int $thresholdMinutes Minutes after ticket creation to trigger
     * @param string $action Action to take (e.g., 'notify_manager', 'reassign', 'change_priority')
     * @param string|null $target Optional target (e.g., user ID or email for notification)
     */
    public function __construct(
        public int $thresholdMinutes,
        public string $action,
        public ?string $target,
    ) {}
}
