<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\CustomEvent;
use Pulsar\Extension\Analytics\Domain\Goal;
use Pulsar\Extension\Analytics\Domain\GoalType;
use Pulsar\Extension\Analytics\Domain\PageView;

final class GoalTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $now = new DateTimeImmutable('2026-03-01');

        $goal = new Goal(
            id: 'goal-1',
            siteId: 'site-1',
            name: 'Signup Page',
            goalType: GoalType::PageVisit,
            targetValue: '/signup',
            createdAt: $now,
        );

        self::assertSame('goal-1', $goal->id);
        self::assertSame('site-1', $goal->siteId);
        self::assertSame('Signup Page', $goal->name);
        self::assertSame(GoalType::PageVisit, $goal->goalType);
        self::assertSame('/signup', $goal->targetValue);
        self::assertSame($now, $goal->createdAt);
    }

    #[Test]
    public function matchesPageViewWithExactPath(): void
    {
        $goal = new Goal(
            id: 'g1',
            siteId: 's1',
            name: 'Checkout',
            goalType: GoalType::PageVisit,
            targetValue: '/checkout',
        );

        $pageView = new PageView(
            id: 'pv1',
            siteId: 's1',
            visitorId: 'v1',
            sessionId: 'sess1',
            pathname: '/checkout',
        );

        self::assertTrue($goal->matchesPageView($pageView));
    }

    #[Test]
    public function matchesPageViewWithWildcardPattern(): void
    {
        $goal = new Goal(
            id: 'g1',
            siteId: 's1',
            name: 'Blog Pages',
            goalType: GoalType::PageVisit,
            targetValue: '/blog/*',
        );

        $matching = new PageView(
            id: 'pv1',
            siteId: 's1',
            visitorId: 'v1',
            sessionId: 'sess1',
            pathname: '/blog/my-post',
        );

        $nonMatching = new PageView(
            id: 'pv2',
            siteId: 's1',
            visitorId: 'v1',
            sessionId: 'sess1',
            pathname: '/about',
        );

        self::assertTrue($goal->matchesPageView($matching));
        self::assertFalse($goal->matchesPageView($nonMatching));
    }

    #[Test]
    public function pageVisitGoalDoesNotMatchEvents(): void
    {
        $goal = new Goal(
            id: 'g1',
            siteId: 's1',
            name: 'Checkout',
            goalType: GoalType::PageVisit,
            targetValue: '/checkout',
        );

        $event = new CustomEvent(
            id: 'e1',
            siteId: 's1',
            visitorId: 'v1',
            sessionId: 'sess1',
            eventName: '/checkout',
        );

        self::assertFalse($goal->matchesEvent($event));
    }

    #[Test]
    public function matchesEventWithExactName(): void
    {
        $goal = new Goal(
            id: 'g1',
            siteId: 's1',
            name: 'Signup',
            goalType: GoalType::CustomEvent,
            targetValue: 'signup',
        );

        $matching = new CustomEvent(
            id: 'e1',
            siteId: 's1',
            visitorId: 'v1',
            sessionId: 'sess1',
            eventName: 'signup',
        );

        $nonMatching = new CustomEvent(
            id: 'e2',
            siteId: 's1',
            visitorId: 'v1',
            sessionId: 'sess1',
            eventName: 'login',
        );

        self::assertTrue($goal->matchesEvent($matching));
        self::assertFalse($goal->matchesEvent($nonMatching));
    }

    #[Test]
    public function customEventGoalDoesNotMatchPageViews(): void
    {
        $goal = new Goal(
            id: 'g1',
            siteId: 's1',
            name: 'Signup Event',
            goalType: GoalType::CustomEvent,
            targetValue: 'signup',
        );

        $pageView = new PageView(
            id: 'pv1',
            siteId: 's1',
            visitorId: 'v1',
            sessionId: 'sess1',
            pathname: '/signup',
        );

        self::assertFalse($goal->matchesPageView($pageView));
    }
}
