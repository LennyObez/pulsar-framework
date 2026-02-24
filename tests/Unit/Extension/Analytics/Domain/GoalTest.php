<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\CustomEvent;
use Pulsar\Extension\Analytics\Domain\Goal;
use Pulsar\Extension\Analytics\Domain\GoalType;
use Pulsar\Extension\Analytics\Domain\PageView;

#[CoversClass(Goal::class)]
final class GoalTest extends TestCase
{
    #[Test]
    public function construction(): void
    {
        $now = new DateTimeImmutable('2026-03-07');
        $goal = new Goal(
            id: 'g-1',
            siteId: 'site-1',
            name: 'Signup page',
            goalType: GoalType::PageVisit,
            targetValue: '/signup*',
            createdAt: $now,
        );

        self::assertSame('g-1', $goal->id);
        self::assertSame('site-1', $goal->siteId);
        self::assertSame('Signup page', $goal->name);
        self::assertSame(GoalType::PageVisit, $goal->goalType);
        self::assertSame('/signup*', $goal->targetValue);
        self::assertSame($now, $goal->createdAt);
    }

    #[Test]
    public function matchesPageViewWithMatchingPattern(): void
    {
        $goal = new Goal(
            id: 'g-1',
            siteId: 'site-1',
            name: 'Signup',
            goalType: GoalType::PageVisit,
            targetValue: '/signup*',
        );

        $pageView = new PageView(
            id: 'pv-1',
            siteId: 'site-1',
            visitorId: 'v',
            sessionId: 's',
            pathname: '/signup/step-1',
        );

        self::assertTrue($goal->matchesPageView($pageView));
    }

    #[Test]
    public function matchesPageViewWithNonMatchingPattern(): void
    {
        $goal = new Goal(
            id: 'g-1',
            siteId: 's',
            name: 'Signup',
            goalType: GoalType::PageVisit,
            targetValue: '/signup*',
        );

        $pageView = new PageView(
            id: 'pv-1',
            siteId: 's',
            visitorId: 'v',
            sessionId: 's',
            pathname: '/about',
        );

        self::assertFalse($goal->matchesPageView($pageView));
    }

    #[Test]
    public function matchesPageViewReturnsFalseForEventGoal(): void
    {
        $goal = new Goal(
            id: 'g-1',
            siteId: 's',
            name: 'CTA click',
            goalType: GoalType::CustomEvent,
            targetValue: 'click_cta',
        );

        $pageView = new PageView(
            id: 'pv-1',
            siteId: 's',
            visitorId: 'v',
            sessionId: 's',
            pathname: '/signup',
        );

        self::assertFalse($goal->matchesPageView($pageView));
    }

    #[Test]
    public function matchesEventWithMatchingName(): void
    {
        $goal = new Goal(
            id: 'g-1',
            siteId: 's',
            name: 'CTA click',
            goalType: GoalType::CustomEvent,
            targetValue: 'click_cta',
        );

        $event = new CustomEvent(
            id: 'e-1',
            siteId: 's',
            visitorId: 'v',
            sessionId: 's',
            eventName: 'click_cta',
        );

        self::assertTrue($goal->matchesEvent($event));
    }

    #[Test]
    public function matchesEventWithNonMatchingName(): void
    {
        $goal = new Goal(
            id: 'g-1',
            siteId: 's',
            name: 'CTA click',
            goalType: GoalType::CustomEvent,
            targetValue: 'click_cta',
        );

        $event = new CustomEvent(
            id: 'e-1',
            siteId: 's',
            visitorId: 'v',
            sessionId: 's',
            eventName: 'scroll_bottom',
        );

        self::assertFalse($goal->matchesEvent($event));
    }

    #[Test]
    public function matchesEventReturnsFalseForPageGoal(): void
    {
        $goal = new Goal(
            id: 'g-1',
            siteId: 's',
            name: 'Visit signup',
            goalType: GoalType::PageVisit,
            targetValue: '/signup',
        );

        $event = new CustomEvent(
            id: 'e-1',
            siteId: 's',
            visitorId: 'v',
            sessionId: 's',
            eventName: 'click_cta',
        );

        self::assertFalse($goal->matchesEvent($event));
    }

    #[Test]
    public function matchesPageViewExactMatch(): void
    {
        $goal = new Goal(
            id: 'g-1',
            siteId: 's',
            name: 'Exact page',
            goalType: GoalType::PageVisit,
            targetValue: '/pricing',
        );

        $matching = new PageView(id: 'pv', siteId: 's', visitorId: 'v', sessionId: 's', pathname: '/pricing');
        $nonMatching = new PageView(id: 'pv', siteId: 's', visitorId: 'v', sessionId: 's', pathname: '/pricing/enterprise');

        self::assertTrue($goal->matchesPageView($matching));
        self::assertFalse($goal->matchesPageView($nonMatching));
    }
}
