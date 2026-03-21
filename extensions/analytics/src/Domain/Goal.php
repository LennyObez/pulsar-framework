<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A goal definition that triggers conversions on matching events.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Goal
{
    public function __construct(
        public string $id,
        public string $siteId,
        public string $name,
        public GoalType $goalType,
        public string $targetValue,
        public DateTimeImmutable $createdAt = new DateTimeImmutable(),
    ) {}

    /**
     * Check if a page view matches this goal.
     */
    public function matchesPageView(PageView $pageView): bool
    {
        if ($this->goalType !== GoalType::PageVisit) {
            return false;
        }

        return fnmatch($this->targetValue, $pageView->pathname);
    }

    /**
     * Check if a custom event matches this goal.
     */
    public function matchesEvent(CustomEvent $event): bool
    {
        if ($this->goalType !== GoalType::CustomEvent) {
            return false;
        }

        return $this->targetValue === $event->eventName;
    }
}
