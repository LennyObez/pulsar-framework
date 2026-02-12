<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Profile;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\ReputationLevel;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Profile\ForumProfile;

final class ForumProfileTest extends TestCase
{
    #[Test]
    public function createSetsDefaults(): void
    {
        $profile = ForumProfile::create(id: 'prof-1', userId: 'user-1');

        self::assertSame('prof-1', $profile->id);
        self::assertNull($profile->tenantId);
        self::assertSame('user-1', $profile->userId);
        self::assertSame(0, $profile->reputationScore);
        self::assertSame(0, $profile->postCount);
        self::assertSame(0, $profile->threadCount);
        self::assertFalse($profile->isBanned);
        self::assertNull($profile->banReason);
        self::assertNull($profile->bannedAt);
        self::assertNull($profile->banExpiresAt);
    }

    #[Test]
    public function createWithTenantId(): void
    {
        $profile = ForumProfile::create(id: 'prof-1', userId: 'user-1', tenantId: 'tenant-1');

        self::assertSame('tenant-1', $profile->tenantId);
    }

    #[Test]
    public function addReputationIncreasesScore(): void
    {
        $profile = ForumProfile::create(id: 'prof-1', userId: 'user-1');
        $updated = $profile->addReputation(10);

        self::assertSame(10, $updated->reputationScore);
    }

    #[Test]
    public function addReputationCanBeNegative(): void
    {
        $profile = ForumProfile::create(id: 'prof-1', userId: 'user-1');
        $updated = $profile->addReputation(10)->addReputation(-5);

        self::assertSame(5, $updated->reputationScore);
    }

    #[Test]
    public function incrementPostCount(): void
    {
        $profile = ForumProfile::create(id: 'prof-1', userId: 'user-1');
        $updated = $profile->incrementPostCount();

        self::assertSame(1, $updated->postCount);
    }

    #[Test]
    public function incrementThreadCount(): void
    {
        $profile = ForumProfile::create(id: 'prof-1', userId: 'user-1');
        $updated = $profile->incrementThreadCount();

        self::assertSame(1, $updated->threadCount);
    }

    #[Test]
    public function banSetsAllFields(): void
    {
        $profile = ForumProfile::create(id: 'prof-1', userId: 'user-1');
        $expires = new DateTimeImmutable('+7 days');
        $banned = $profile->ban('Spam', $expires);

        self::assertTrue($banned->isBanned);
        self::assertSame('Spam', $banned->banReason);
        self::assertNotNull($banned->bannedAt);
        self::assertSame($expires, $banned->banExpiresAt);
    }

    #[Test]
    public function banWithoutExpiryIsPermanent(): void
    {
        $profile = ForumProfile::create(id: 'prof-1', userId: 'user-1');
        $banned = $profile->ban('Permanent ban');

        self::assertTrue($banned->isBanned);
        self::assertNull($banned->banExpiresAt);
    }

    #[Test]
    public function banThrowsWhenAlreadyBanned(): void
    {
        $profile = ForumProfile::create(id: 'prof-1', userId: 'user-1');
        $banned = $profile->ban('First ban');

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('banned');
        $banned->ban('Second ban');
    }

    #[Test]
    public function unbanClearsAllBanFields(): void
    {
        $profile = ForumProfile::create(id: 'prof-1', userId: 'user-1');
        $banned = $profile->ban('Spam');
        $unbanned = $banned->unban();

        self::assertFalse($unbanned->isBanned);
        self::assertNull($unbanned->banReason);
        self::assertNull($unbanned->bannedAt);
        self::assertNull($unbanned->banExpiresAt);
    }

    #[Test]
    public function reputationLevelReturnsNewcomerByDefault(): void
    {
        $profile = ForumProfile::create(id: 'prof-1', userId: 'user-1');

        self::assertSame(ReputationLevel::Newcomer, $profile->reputationLevel());
    }

    #[Test]
    public function reputationLevelReflectsScore(): void
    {
        $profile = ForumProfile::create(id: 'prof-1', userId: 'user-1');
        $updated = $profile->addReputation(100);

        self::assertSame(ReputationLevel::Trusted, $updated->reputationLevel());
    }

    #[Test]
    public function isBanExpiredReturnsFalseWhenNotBanned(): void
    {
        $profile = ForumProfile::create(id: 'prof-1', userId: 'user-1');

        self::assertFalse($profile->isBanExpired());
    }

    #[Test]
    public function isBanExpiredReturnsFalseForPermanentBan(): void
    {
        $profile = ForumProfile::create(id: 'prof-1', userId: 'user-1');
        $banned = $profile->ban('Permanent');

        self::assertFalse($banned->isBanExpired());
    }

    #[Test]
    public function isBanExpiredReturnsTrueWhenExpired(): void
    {
        $profile = new ForumProfile(
            id: 'prof-1',
            tenantId: null,
            userId: 'user-1',
            reputationScore: 0,
            postCount: 0,
            threadCount: 0,
            isBanned: true,
            banReason: 'Temp ban',
            bannedAt: new DateTimeImmutable('-2 days'),
            banExpiresAt: new DateTimeImmutable('-1 day'),
            createdAt: new DateTimeImmutable('-30 days'),
            updatedAt: new DateTimeImmutable('-2 days'),
        );

        self::assertTrue($profile->isBanExpired());
    }
}
