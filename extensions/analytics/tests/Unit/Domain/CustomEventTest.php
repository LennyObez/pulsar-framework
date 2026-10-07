<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\CustomEvent;

final class CustomEventTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $now = new DateTimeImmutable('2026-01-15 10:30:00');

        $event = new CustomEvent(
            id: 'evt-1',
            siteId: 'site-1',
            visitorId: 'visitor-1',
            sessionId: 'session-1',
            eventName: 'signup',
            eventProps: ['plan' => 'pro'],
            revenueValue: 29.99,
            pathname: '/pricing',
            createdAt: $now,
        );

        self::assertSame('evt-1', $event->id);
        self::assertSame('site-1', $event->siteId);
        self::assertSame('visitor-1', $event->visitorId);
        self::assertSame('session-1', $event->sessionId);
        self::assertSame('signup', $event->eventName);
        self::assertSame(['plan' => 'pro'], $event->eventProps);
        self::assertSame(29.99, $event->revenueValue);
        self::assertSame('/pricing', $event->pathname);
        self::assertSame($now, $event->createdAt);
    }

    #[Test]
    public function constructWithDefaults(): void
    {
        $event = new CustomEvent(
            id: 'evt-2',
            siteId: 'site-1',
            visitorId: 'visitor-1',
            sessionId: 'session-1',
            eventName: 'click',
        );

        self::assertSame([], $event->eventProps);
        self::assertNull($event->revenueValue);
        self::assertSame('', $event->pathname);
        self::assertInstanceOf(DateTimeImmutable::class, $event->createdAt);
    }
}
