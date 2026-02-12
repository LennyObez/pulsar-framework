<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\Session;

final class SessionTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $start = new DateTimeImmutable('2026-03-01 10:00:00');
        $end = new DateTimeImmutable('2026-03-01 10:05:00');

        $session = new Session(
            id: 's1',
            siteId: 'site-1',
            visitorId: 'visitor-1',
            sessionId: 'sess-1',
            entryPage: '/home',
            exitPage: '/about',
            pageCount: 3,
            durationSeconds: 300,
            isBounce: false,
            startedAt: $start,
            endedAt: $end,
        );

        self::assertSame('s1', $session->id);
        self::assertSame('site-1', $session->siteId);
        self::assertSame('visitor-1', $session->visitorId);
        self::assertSame('sess-1', $session->sessionId);
        self::assertSame('/home', $session->entryPage);
        self::assertSame('/about', $session->exitPage);
        self::assertSame(3, $session->pageCount);
        self::assertSame(300, $session->durationSeconds);
        self::assertFalse($session->isBounce);
        self::assertSame($start, $session->startedAt);
        self::assertSame($end, $session->endedAt);
    }

    #[Test]
    public function constructWithDefaults(): void
    {
        $session = new Session(
            id: 's1',
            siteId: 'site-1',
            visitorId: 'visitor-1',
            sessionId: 'sess-1',
            entryPage: '/',
            exitPage: '/',
        );

        self::assertSame(1, $session->pageCount);
        self::assertSame(0, $session->durationSeconds);
        self::assertTrue($session->isBounce);
    }

    #[Test]
    public function withPageViewUpdatesExitPageAndCounters(): void
    {
        $start = new DateTimeImmutable('2026-03-01 10:00:00');

        $session = new Session(
            id: 's1',
            siteId: 'site-1',
            visitorId: 'visitor-1',
            sessionId: 'sess-1',
            entryPage: '/',
            exitPage: '/',
            pageCount: 1,
            durationSeconds: 0,
            isBounce: true,
            startedAt: $start,
            endedAt: $start,
        );

        $later = new DateTimeImmutable('2026-03-01 10:02:30');
        $updated = $session->withPageView('/about', $later);

        self::assertSame('/', $updated->entryPage);
        self::assertSame('/about', $updated->exitPage);
        self::assertSame(2, $updated->pageCount);
        self::assertSame(150, $updated->durationSeconds);
        self::assertFalse($updated->isBounce);
        self::assertSame($later, $updated->endedAt);
        self::assertSame($start, $updated->startedAt);
    }

    #[Test]
    public function withPageViewClampsNegativeDuration(): void
    {
        $start = new DateTimeImmutable('2026-03-01 10:05:00');
        $session = new Session(
            id: 's1',
            siteId: 'site-1',
            visitorId: 'visitor-1',
            sessionId: 'sess-1',
            entryPage: '/',
            exitPage: '/',
            startedAt: $start,
            endedAt: $start,
        );

        $earlier = new DateTimeImmutable('2026-03-01 10:00:00');
        $updated = $session->withPageView('/page', $earlier);

        self::assertSame(0, $updated->durationSeconds);
    }
}
