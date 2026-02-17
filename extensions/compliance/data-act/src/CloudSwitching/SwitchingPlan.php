<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\CloudSwitching;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\DataAct\Portability\ExportRequest;

/**
 * Represents a cloud switching migration plan per Data Act Art. 25.
 *
 * Tracks the full lifecycle of a customer's migration from the
 * current cloud provider to a target provider, including data
 * export, transition deadlines, and completion status.
 */
#[Api(since: '1.0.0')]
final readonly class SwitchingPlan
{
    public function __construct(
        public string $customerId,
        public string $targetProvider,
        public ExportRequest $exportRequest,
        public DateTimeImmutable $initiatedAt,
        public DateTimeImmutable $transitionDeadline,
        public SwitchingStatus $status,
    ) {}

    /**
     * Whether the transition deadline has been exceeded.
     */
    #[NoDiscard]
    public function isOverdue(): bool
    {
        return $this->status !== SwitchingStatus::Completed
            && new DateTimeImmutable() > $this->transitionDeadline;
    }
}
