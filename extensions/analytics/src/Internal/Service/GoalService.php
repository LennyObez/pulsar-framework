<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Service;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\GoalServiceInterface;
use Pulsar\Extension\Analytics\Domain\CustomEvent;
use Pulsar\Extension\Analytics\Domain\Goal;
use Pulsar\Extension\Analytics\Domain\GoalConversion;
use Pulsar\Extension\Analytics\Domain\GoalType;
use Pulsar\Extension\Analytics\Domain\PageView;
use Pulsar\Extension\Analytics\Exception\AnalyticsException;
use Pulsar\Extension\Analytics\Internal\Repository\DbGoalConversionRepository;
use Pulsar\Extension\Analytics\Internal\Repository\DbGoalRepository;

/**
 * Goal CRUD and conversion evaluation.
 */
#[Internal(reason: 'Goal management; use GoalServiceInterface')]
final readonly class GoalService implements GoalServiceInterface
{
    public function __construct(
        private DbGoalRepository $goalRepository,
        private DbGoalConversionRepository $conversionRepository,
    ) {}

    #[Override]
    public function create(string $siteId, string $name, GoalType $goalType, string $targetValue): Goal
    {
        $goal = new Goal(
            id: bin2hex(random_bytes(18)),
            siteId: $siteId,
            name: $name,
            goalType: $goalType,
            targetValue: $targetValue,
            createdAt: new DateTimeImmutable(),
        );

        $this->goalRepository->save($goal);

        return $goal;
    }

    #[Override]
    public function update(string $id, string $name, GoalType $goalType, string $targetValue): Goal
    {
        $existing = $this->goalRepository->findById($id);

        if ($existing === null) {
            throw AnalyticsException::notFound('Goal', $id);
        }

        $updated = new Goal(
            id: $existing->id,
            siteId: $existing->siteId,
            name: $name,
            goalType: $goalType,
            targetValue: $targetValue,
            createdAt: $existing->createdAt,
        );

        $this->goalRepository->save($updated);

        return $updated;
    }

    #[Override]
    public function delete(string $id): void
    {
        $existing = $this->goalRepository->findById($id);

        if ($existing === null) {
            throw AnalyticsException::notFound('Goal', $id);
        }

        $this->goalRepository->delete($id);
    }

    #[Override]
    public function listForSite(string $siteId): array
    {
        return $this->goalRepository->findBySite($siteId);
    }

    #[Override]
    public function findById(string $id): ?Goal
    {
        return $this->goalRepository->findById($id);
    }

    #[Override]
    public function checkPageViewConversions(PageView $pageView): void
    {
        $goals = $this->goalRepository->findBySite($pageView->siteId);

        foreach ($goals as $goal) {
            if ($goal->matchesPageView($pageView)) {
                $this->recordConversion($goal, $pageView->visitorId, $pageView->sessionId, $pageView->siteId);
            }
        }
    }

    #[Override]
    public function checkEventConversions(CustomEvent $event): void
    {
        $goals = $this->goalRepository->findBySite($event->siteId);

        foreach ($goals as $goal) {
            if ($goal->matchesEvent($event)) {
                $this->recordConversion($goal, $event->visitorId, $event->sessionId, $event->siteId, $event->revenueValue);
            }
        }
    }

    private function recordConversion(Goal $goal, string $visitorId, string $sessionId, string $siteId, ?float $revenue = null): void
    {
        $conversion = new GoalConversion(
            id: bin2hex(random_bytes(18)),
            goalId: $goal->id,
            siteId: $siteId,
            visitorId: $visitorId,
            sessionId: $sessionId,
            revenueValue: $revenue,
            createdAt: new DateTimeImmutable(),
        );

        $this->conversionRepository->save($conversion);
    }
}
