<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Internal\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\SessionRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\Session;
use Pulsar\Extension\Analytics\Domain\VisitorId;
use Pulsar\Extension\Analytics\Internal\Service\SessionResolver;
use Pulsar\Security\Crypto\MasterKey;

#[CoversClass(SessionResolver::class)]
final class SessionResolverTest extends TestCase
{
    private SessionRepositoryInterface&Stub $sessionRepo;
    private SessionResolver $resolver;
    private string $visitorKey;

    protected function setUp(): void
    {
        $this->sessionRepo = $this->createStub(SessionRepositoryInterface::class);
        $this->resolver = new SessionResolver(
            $this->sessionRepo,
        );

        $masterKey = MasterKey::fromHex(bin2hex(random_bytes(32)));
        $this->visitorKey = $masterKey->deriveSubKey(20, 'anal_vis');
    }

    #[Test]
    public function newVisitorCreatesNewSession(): void
    {
        $this->sessionRepo->method('findActiveByVisitor')->willReturn(null);

        $visitorId = VisitorId::fromHash('visitor-hash-abc');
        $now = new DateTimeImmutable('2024-06-15 14:30:00');

        $session = $this->resolver->resolve($visitorId, $now, '/home', 'site-1', $this->visitorKey);

        self::assertSame('site-1', $session->siteId);
        self::assertSame('visitor-hash-abc', $session->visitorId);
        self::assertSame('/home', $session->entryPage);
        self::assertSame('/home', $session->exitPage);
        self::assertSame(1, $session->pageCount);
        self::assertSame(0, $session->durationSeconds);
        self::assertTrue($session->isBounce);
    }

    #[Test]
    public function sameVisitorWithin30MinutesContinuesSession(): void
    {
        $startTime = new DateTimeImmutable('2024-06-15 14:00:00');
        $existingSession = new Session(
            id: 'session-id-1',
            siteId: 'site-1',
            visitorId: 'visitor-hash-abc',
            sessionId: 'session-id-1',
            entryPage: '/home',
            exitPage: '/home',
            pageCount: 1,
            durationSeconds: 0,
            isBounce: true,
            startedAt: $startTime,
            endedAt: $startTime,
        );

        $this->sessionRepo->method('findActiveByVisitor')->willReturn($existingSession);

        $visitorId = VisitorId::fromHash('visitor-hash-abc');
        $now = new DateTimeImmutable('2024-06-15 14:20:00');

        $session = $this->resolver->resolve($visitorId, $now, '/about', 'site-1', $this->visitorKey);

        self::assertSame('session-id-1', $session->id);
        self::assertSame('/about', $session->exitPage);
        self::assertSame(2, $session->pageCount);
        self::assertFalse($session->isBounce);
        self::assertSame(1200, $session->durationSeconds); // 20 minutes
    }

    #[Test]
    public function sameVisitorAfter30MinutesStartsNewSession(): void
    {
        // Repository returns null (no active session within window)
        $this->sessionRepo->method('findActiveByVisitor')->willReturn(null);

        $visitorId = VisitorId::fromHash('visitor-hash-abc');
        $now = new DateTimeImmutable('2024-06-15 15:00:00');

        $session = $this->resolver->resolve($visitorId, $now, '/pricing', 'site-1', $this->visitorKey);

        // Should be a brand new session
        self::assertSame('/pricing', $session->entryPage);
        self::assertSame('/pricing', $session->exitPage);
        self::assertSame(1, $session->pageCount);
        self::assertTrue($session->isBounce);
    }

    #[Test]
    public function midnightGracePeriodContinuesSessionWithYesterdayVisitor(): void
    {
        $startTime = new DateTimeImmutable('2024-06-14 23:50:00');
        $existingSession = new Session(
            id: 'session-id-2',
            siteId: 'site-1',
            visitorId: 'yesterday-visitor-hash',
            sessionId: 'session-id-2',
            entryPage: '/blog',
            exitPage: '/blog/article',
            pageCount: 3,
            durationSeconds: 600,
            isBounce: false,
            startedAt: $startTime,
            endedAt: new DateTimeImmutable('2024-06-14 23:55:00'),
        );

        // First call (today's visitor) returns null, second call (yesterday's visitor) returns session
        $this->sessionRepo->method('findActiveByVisitor')->willReturnCallback(
            static function (string $siteId, string $visitorId) use ($existingSession): ?Session {
                if ($visitorId === 'yesterday-visitor-hash') {
                    return $existingSession;
                }

                return null;
            },
        );

        $todayVisitor = VisitorId::fromHash('today-visitor-hash');
        $yesterdayVisitor = VisitorId::fromHash('yesterday-visitor-hash');
        // 12:15 AM — within the 30-minute midnight grace period
        $now = new DateTimeImmutable('2024-06-15 00:15:00');

        $session = $this->resolver->resolve(
            $todayVisitor,
            $now,
            '/blog/comments',
            'site-1',
            $this->visitorKey,
            $yesterdayVisitor,
        );

        self::assertSame('session-id-2', $session->id);
        self::assertSame('/blog/comments', $session->exitPage);
        self::assertSame(4, $session->pageCount);
    }

    #[Test]
    public function midnightGracePeriodNotUsedOutsideWindow(): void
    {
        // Repository always returns null
        $this->sessionRepo->method('findActiveByVisitor')->willReturn(null);

        $todayVisitor = VisitorId::fromHash('today-visitor-hash');
        $yesterdayVisitor = VisitorId::fromHash('yesterday-visitor-hash');
        // 2:00 AM — outside the 30-minute midnight grace period
        $now = new DateTimeImmutable('2024-06-15 02:00:00');

        $session = $this->resolver->resolve(
            $todayVisitor,
            $now,
            '/home',
            'site-1',
            $this->visitorKey,
            $yesterdayVisitor,
        );

        // Should be a new session, not continuing yesterday's
        self::assertSame(1, $session->pageCount);
        self::assertTrue($session->isBounce);
    }

    #[Test]
    public function newSessionHasValidSessionId(): void
    {
        $this->sessionRepo->method('findActiveByVisitor')->willReturn(null);

        $visitorId = VisitorId::fromHash('visitor-hash-xyz');
        $now = new DateTimeImmutable('2024-06-15 14:30:00');

        $session = $this->resolver->resolve($visitorId, $now, '/home', 'site-1', $this->visitorKey);

        self::assertNotEmpty($session->sessionId);
        self::assertSame($session->id, $session->sessionId);
    }
}
