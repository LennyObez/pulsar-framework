<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\GoalConversion;

final class GoalConversionTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $now = new DateTimeImmutable('2026-03-01 12:00:00');

        $conversion = new GoalConversion(
            id: 'conv-1',
            goalId: 'goal-1',
            siteId: 'site-1',
            visitorId: 'visitor-1',
            sessionId: 'session-1',
            revenueValue: 99.99,
            createdAt: $now,
        );

        self::assertSame('conv-1', $conversion->id);
        self::assertSame('goal-1', $conversion->goalId);
        self::assertSame('site-1', $conversion->siteId);
        self::assertSame('visitor-1', $conversion->visitorId);
        self::assertSame('session-1', $conversion->sessionId);
        self::assertSame(99.99, $conversion->revenueValue);
        self::assertSame($now, $conversion->createdAt);
    }

    #[Test]
    public function constructWithDefaults(): void
    {
        $conversion = new GoalConversion(
            id: 'conv-2',
            goalId: 'goal-2',
            siteId: 'site-1',
            visitorId: 'visitor-1',
            sessionId: 'session-1',
        );

        self::assertNull($conversion->revenueValue);
        self::assertInstanceOf(DateTimeImmutable::class, $conversion->createdAt);
    }
}
