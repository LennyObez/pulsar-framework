<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\SessionRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\Session;
use Pulsar\Extension\Analytics\Domain\VisitorId;
use Pulsar\Extension\Analytics\Internal\Service\SessionResolver;

final class SessionResolverTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        $this->key = sodium_crypto_generichash_keygen();
    }

    #[Test]
    public function createsNewSessionWhenNoActiveSessionExists(): void
    {
        $repo = $this->createMock(SessionRepositoryInterface::class);
        $repo->method('findActiveByVisitor')->willReturn(null);
        $repo->expects(self::once())->method('save');

        $resolver = new SessionResolver($repo);
        $visitorId = VisitorId::fromHash('visitor-abc');
        $now = new DateTimeImmutable('2026-03-01 10:00:00');

        $session = $resolver->resolve($visitorId, $now, '/home', 'site-1', $this->key);

        self::assertSame('site-1', $session->siteId);
        self::assertSame('visitor-abc', $session->visitorId);
        self::assertSame('/home', $session->entryPage);
        self::assertSame('/home', $session->exitPage);
        self::assertSame(1, $session->pageCount);
        self::assertTrue($session->isBounce);
        self::assertSame(0, $session->durationSeconds);
    }

    #[Test]
    public function continuesExistingActiveSession(): void
    {
        $startTime = new DateTimeImmutable('2026-03-01 10:00:00');
        $existing = new Session(
            id: 's1',
            siteId: 'site-1',
            visitorId: 'visitor-abc',
            sessionId: 'sess-1',
            entryPage: '/home',
            exitPage: '/home',
            pageCount: 1,
            durationSeconds: 0,
            isBounce: true,
            startedAt: $startTime,
            endedAt: $startTime,
        );

        $repo = $this->createMock(SessionRepositoryInterface::class);
        $repo->method('findActiveByVisitor')->willReturn($existing);
        $repo->expects(self::once())->method('update');

        $resolver = new SessionResolver($repo);
        $visitorId = VisitorId::fromHash('visitor-abc');
        $now = new DateTimeImmutable('2026-03-01 10:05:00');

        $session = $resolver->resolve($visitorId, $now, '/about', 'site-1', $this->key);

        self::assertSame('/about', $session->exitPage);
        self::assertSame(2, $session->pageCount);
        self::assertFalse($session->isBounce);
        self::assertSame(300, $session->durationSeconds);
    }

    #[Test]
    public function midnightGraceUsesYesterdayVisitorId(): void
    {
        $startTime = new DateTimeImmutable('2026-03-01 23:50:00');
        $existing = new Session(
            id: 's1',
            siteId: 'site-1',
            visitorId: 'yesterday-visitor',
            sessionId: 'sess-1',
            entryPage: '/home',
            exitPage: '/home',
            pageCount: 1,
            durationSeconds: 0,
            isBounce: true,
            startedAt: $startTime,
            endedAt: $startTime,
        );

        $repo = $this->createMock(SessionRepositoryInterface::class);
        $repo->expects(self::exactly(2))
            ->method('findActiveByVisitor')
            ->willReturnCallback(static function (string $siteId, string $visitorId) use ($existing): ?Session {
                if ($visitorId === 'yesterday-visitor') {
                    return $existing;
                }
                return null;
            });
        $repo->expects(self::once())->method('update');

        $resolver = new SessionResolver($repo);
        $todayVisitor = VisitorId::fromHash('today-visitor');
        $yesterdayVisitor = VisitorId::fromHash('yesterday-visitor');

        // Time within midnight grace (00:15 UTC)
        $now = new DateTimeImmutable('2026-03-02 00:15:00');
        $session = $resolver->resolve($todayVisitor, $now, '/page2', 'site-1', $this->key, $yesterdayVisitor);

        self::assertSame(2, $session->pageCount);
        self::assertFalse($session->isBounce);
    }

    #[Test]
    public function noMidnightGraceOutsideWindow(): void
    {
        $repo = $this->createMock(SessionRepositoryInterface::class);
        // Only 1 call (today's visitor), midnight grace not triggered at 14:00
        $repo->expects(self::once())
            ->method('findActiveByVisitor')
            ->willReturn(null);
        $repo->expects(self::once())->method('save');

        $resolver = new SessionResolver($repo);
        $todayVisitor = VisitorId::fromHash('today-visitor');
        $yesterdayVisitor = VisitorId::fromHash('yesterday-visitor');

        $now = new DateTimeImmutable('2026-03-02 14:00:00');
        $session = $resolver->resolve($todayVisitor, $now, '/home', 'site-1', $this->key, $yesterdayVisitor);

        self::assertSame(1, $session->pageCount);
    }
}
