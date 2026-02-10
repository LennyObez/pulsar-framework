<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Analytics\Domain\CustomEvent;
use Pulsar\Extension\Analytics\Domain\Goal;
use Pulsar\Extension\Analytics\Domain\GoalType;
use Pulsar\Extension\Analytics\Domain\PageView;

/**
 * Manage goals and evaluate conversions.
 */
#[Api(since: '1.0.0')]
interface GoalServiceInterface
{
    public function create(string $siteId, string $name, GoalType $goalType, string $targetValue): Goal;

    public function update(string $id, string $name, GoalType $goalType, string $targetValue): Goal;

    public function delete(string $id): void;

    /**
     * @return list<Goal>
     */
    public function listForSite(string $siteId): array;

    public function findById(string $id): ?Goal;

    /**
     * Evaluate all goals for a site against a page view and record conversions.
     */
    public function checkPageViewConversions(PageView $pageView): void;

    /**
     * Evaluate all goals for a site against a custom event and record conversions.
     */
    public function checkEventConversions(CustomEvent $event): void;
}
