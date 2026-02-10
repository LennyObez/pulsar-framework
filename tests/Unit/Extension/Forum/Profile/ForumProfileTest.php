<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Profile;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\ReputationLevel;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Profile\ForumProfile;

#[CoversClass(ForumProfile::class)]
final class ForumProfileTest extends TestCase
{
    #[Test]
    public function createFactoryProducesCleanProfile(): void
    {
        $profile = ForumProfile::create(
            id: 'profile-001',
            userId: 'user-001',
            tenantId: 'tenant-001',
        );

        self::assertSame('profile-001', $profile->id);
        self::assertSame('tenant-001', $profile->tenantId);
        self::assertSame('user-001', $profile->userId);
        self::assertSame(0, $profile->reputationScore);
        self::assertSame(0, $profile->postCount);
        self::assertSame(0, $profile->threadCount);
        self::assertFalse($profile->isBanned);
        self::assertNull($profile->banReason);
        self::assertNull($profile->bannedAt);
        self::assertNull($profile->banExpiresAt);
    }

    #[Test]
    public function createWithoutTenant(): void
    {
        $profile = ForumProfile::create(id: 'p-001', userId: 'u-001');
        self::assertNull($profile->tenantId);
    }

    #[Test]
    public function addReputationPositive(): void
    {
        $profile = ForumProfile::create(id: 'p-001', userId: 'u-001');
        $updated = $profile->addReputation(10);
        self::assertSame(10, $updated->reputationScore);
        self::assertSame(0, $profile->reputationScore);
    }

    #[Test]
    public function addReputationNegative(): void
    {
        $profile = ForumProfile::create(id: 'p-001', userId: 'u-001');
        $down = $profile->addReputation(20)->addReputation(-5);
        self::assertSame(15, $down->reputationScore);
    }

    #[Test]
    public function addReputationAccumulates(): void
    {
        $profile = ForumProfile::create(id: 'p-001', userId: 'u-001');
        $result = $profile->addReputation(5)->addReputation(10)->addReputation(3);
        self::assertSame(18, $result->reputationScore);
    }

    #[Test]
    public function incrementPostCount(): void
    {
        $profile = ForumProfile::create(id: 'p-001', userId: 'u-001');
        $updated = $profile->incrementPostCount();
        self::assertSame(1, $updated->postCount);
        self::assertSame(0, $profile->postCount);
    }

    #[Test]
    public function incrementPostCountMultipleTimes(): void
    {
        $profile = ForumProfile::create(id: 'p-001', userId: 'u-001');
        $result = $profile->incrementPostCount()->incrementPostCount()->incrementPostCount();
        self::assertSame(3, $result->postCount);
    }

    #[Test]
    public function incrementThreadCount(): void
    {
        $profile = ForumProfile::create(id: 'p-001', userId: 'u-001');
        $updated = $profile->incrementThreadCount();
        self::assertSame(1, $updated->threadCount);
        self::assertSame(0, $profile->threadCount);
    }

    #[Test]
    public function banSetsAllBanFields(): void
    {
        $expires = new DateTimeImmutable('+7 days');
        $profile = ForumProfile::create(id: 'p-001', userId: 'u-001');
        $banned = $profile->ban('Spam', $expires);
        self::assertTrue($banned->isBanned);
        self::assertSame('Spam', $banned->banReason);
        self::assertNotNull($banned->bannedAt);
        self::assertSame($expires, $banned->banExpiresAt);
    }

    #[Test]
    public function banPermanentHasNullExpiry(): void
    {
        $profile = ForumProfile::create(id: 'p-001', userId: 'u-001');
        $banned = $profile->ban('Permanent ban');
        self::assertTrue($banned->isBanned);
        self::assertNull($banned->banExpiresAt);
    }

    #[Test]
    public function banThrowsWhenAlreadyBanned(): void
    {
        $profile = ForumProfile::create(id: 'p-001', userId: 'u-001');
        $banned = $profile->ban('First offense');
        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('User is banned from the forum: u-001');
        $banned->ban('Double ban');
    }

    #[Test]
    public function unbanClearsAllBanFields(): void
    {
        $profile = ForumProfile::create(id: 'p-001', userId: 'u-001');
        $unbanned = $profile->ban('Spam')->unban();
        self::assertFalse($unbanned->isBanned);
        self::assertNull($unbanned->banReason);
        self::assertNull($unbanned->bannedAt);
        self::assertNull($unbanned->banExpiresAt);
    }

    #[Test]
    public function reputationLevelReturnsNewcomerForZero(): void
    {
        $profile = ForumProfile::create(id: 'p-001', userId: 'u-001');
        self::assertSame(ReputationLevel::Newcomer, $profile->reputationLevel());
    }

    #[Test]
    public function reputationLevelReturnsCorrectLevelForScore(): void
    {
        $profile = ForumProfile::create(id: 'p-001', userId: 'u-001');
        $withRep = $profile->addReputation(100);
        self::assertSame(ReputationLevel::Trusted, $withRep->reputationLevel());
    }

    #[Test]
    public function isBanExpiredReturnsFalseWhenNotBanned(): void
    {
        $profile = ForumProfile::create(id: 'p-001', userId: 'u-001');
        self::assertFalse($profile->isBanExpired());
    }

    #[Test]
    public function isBanExpiredReturnsFalseForPermanentBan(): void
    {
        $profile = ForumProfile::create(id: 'p-001', userId: 'u-001');
        $banned = $profile->ban('Permanent');
        self::assertFalse($banned->isBanExpired());
    }

    #[Test]
    public function isBanExpiredReturnsTrueWhenExpired(): void
    {
        $now = new DateTimeImmutable();
        $profile = new ForumProfile(
            id: 'p-001',
            tenantId: null,
            userId: 'u-001',
            reputationScore: 0,
            postCount: 0,
            threadCount: 0,
            isBanned: true,
            banReason: 'Temp',
            bannedAt: $now->modify('-2 days'),
            banExpiresAt: $now->modify('-1 day'),
            createdAt: $now,
            updatedAt: $now,
        );
        self::assertTrue($profile->isBanExpired());
    }

    #[Test]
    public function isBanExpiredReturnsFalseWhenNotYetExpired(): void
    {
        $now = new DateTimeImmutable();
        $profile = new ForumProfile(
            id: 'p-001',
            tenantId: null,
            userId: 'u-001',
            reputationScore: 0,
            postCount: 0,
            threadCount: 0,
            isBanned: true,
            banReason: 'Temp',
            bannedAt: $now,
            banExpiresAt: $now->modify('+7 days'),
            createdAt: $now,
            updatedAt: $now,
        );
        self::assertFalse($profile->isBanExpired());
    }
}
