<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Workflow\ContentLock;
use Pulsar\Extension\Cms\Workflow\ContentLockServiceInterface;

#[CoversClass(ContentLock::class)]
final class ContentLockingTest extends TestCase
{
    private InMemoryContentLockService $lockService;

    protected function setUp(): void
    {
        $this->lockService = new InMemoryContentLockService();
    }

    #[Test]
    public function acquireLockSucceedsWhenUnlocked(): void
    {
        $lock = $this->lockService->acquire('content-001', 'user-001');

        self::assertNotNull($lock);
        self::assertSame('content-001', $lock->contentId);
        self::assertSame('user-001', $lock->lockedBy);
        self::assertFalse($lock->isExpired());
    }

    #[Test]
    public function acquireLockFailsWhenLockedByAnother(): void
    {
        $this->lockService->acquire('content-001', 'user-001');

        $secondAttempt = $this->lockService->acquire('content-001', 'user-002');

        self::assertNull($secondAttempt);
    }

    #[Test]
    public function releaseLockSucceedsForOwner(): void
    {
        $this->lockService->acquire('content-001', 'user-001');

        $this->lockService->release('content-001', 'user-001');

        $currentLock = $this->lockService->isLocked('content-001');
        self::assertNull($currentLock);
    }

    #[Test]
    public function heartbeatExtendsLockExpiry(): void
    {
        $lock = $this->lockService->acquire('content-001', 'user-001');
        self::assertNotNull($lock);

        $originalExpiry = $lock->expiresAt;

        // Simulate heartbeat
        $extended = $this->lockService->heartbeat('content-001', 'user-001');

        self::assertNotNull($extended);
        self::assertGreaterThanOrEqual(
            $originalExpiry->getTimestamp(),
            $extended->expiresAt->getTimestamp(),
        );
    }

    #[Test]
    public function expiredLockAllowsNewAcquisition(): void
    {
        // Create a lock that is already expired
        $expiredLock = new ContentLock(
            contentId: 'content-001',
            lockedBy: 'user-001',
            lockedAt: new DateTimeImmutable('-2 hours'),
            expiresAt: new DateTimeImmutable('-1 hour'),
            locale: null,
        );
        $this->lockService->injectLock($expiredLock);

        // Another user should be able to acquire
        $newLock = $this->lockService->acquire('content-001', 'user-002');

        self::assertNotNull($newLock);
        self::assertSame('user-002', $newLock->lockedBy);
    }

    #[Test]
    public function forceUnlockRemovesAnyLock(): void
    {
        $this->lockService->acquire('content-001', 'user-001');

        $this->lockService->forceUnlock('content-001');

        $currentLock = $this->lockService->isLocked('content-001');
        self::assertNull($currentLock);
    }

    #[Test]
    public function cleanupExpiredRemovesStaleLocks(): void
    {
        // Inject an expired lock
        $expired = new ContentLock(
            contentId: 'content-001',
            lockedBy: 'user-001',
            lockedAt: new DateTimeImmutable('-3 hours'),
            expiresAt: new DateTimeImmutable('-1 hour'),
            locale: null,
        );
        $this->lockService->injectLock($expired);

        // Inject an active lock
        $this->lockService->acquire('content-002', 'user-002');

        $cleaned = $this->lockService->cleanupExpired();

        self::assertSame(1, $cleaned);

        // The expired lock is gone
        self::assertNull($this->lockService->isLocked('content-001'));

        // The active lock is still present
        self::assertNotNull($this->lockService->isLocked('content-002'));
    }
}

/**
 * In-memory implementation of ContentLockServiceInterface for integration testing.
 */
final class InMemoryContentLockService implements ContentLockServiceInterface
{
    /** @var array<string, ContentLock> */
    private array $locks = [];

    public function acquire(string $contentId, string $userId, ?string $locale = null): ?ContentLock
    {
        $existing = $this->locks[$contentId] ?? null;

        if ($existing !== null && !$existing->isExpired()) {
            if ($existing->lockedBy === $userId) {
                return $existing;
            }

            return null;
        }

        $now = new DateTimeImmutable();
        $lock = new ContentLock(
            contentId: $contentId,
            lockedBy: $userId,
            lockedAt: $now,
            expiresAt: $now->modify('+30 minutes'),
            locale: $locale,
        );

        $this->locks[$contentId] = $lock;

        return $lock;
    }

    public function release(string $contentId, string $userId): void
    {
        $existing = $this->locks[$contentId] ?? null;

        if ($existing !== null && $existing->lockedBy === $userId) {
            unset($this->locks[$contentId]);
        }
    }

    public function heartbeat(string $contentId, string $userId): ?ContentLock
    {
        $existing = $this->locks[$contentId] ?? null;

        if ($existing === null || $existing->lockedBy !== $userId || $existing->isExpired()) {
            return null;
        }

        $now = new DateTimeImmutable();
        $extended = new ContentLock(
            contentId: $contentId,
            lockedBy: $userId,
            lockedAt: $existing->lockedAt,
            expiresAt: $now->modify('+30 minutes'),
            locale: $existing->locale,
        );

        $this->locks[$contentId] = $extended;

        return $extended;
    }

    public function forceUnlock(string $contentId, ?string $actorId = null): void
    {
        unset($this->locks[$contentId]);
    }

    public function isLocked(string $contentId, ?string $locale = null): ?ContentLock
    {
        $lock = $this->locks[$contentId] ?? null;

        if ($lock === null || $lock->isExpired()) {
            return null;
        }

        return $lock;
    }

    public function getLockInfo(string $contentId): ?ContentLock
    {
        return $this->isLocked($contentId);
    }

    public function cleanupExpired(): int
    {
        $count = 0;

        foreach ($this->locks as $contentId => $lock) {
            if ($lock->isExpired()) {
                unset($this->locks[$contentId]);
                $count++;
            }
        }

        return $count;
    }

    public function injectLock(ContentLock $lock): void
    {
        $this->locks[$lock->contentId] = $lock;
    }
}
