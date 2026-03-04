<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Report;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\BanType;
use Pulsar\Extension\Forum\Report\UserBan;

#[CoversClass(UserBan::class)]
final class UserBanTest extends TestCase
{
    #[Test]
    public function createFactoryGeneratesFieldsCorrectly(): void
    {
        $ban = UserBan::create(
            userId: 'user-1',
            bannedBy: 'mod-1',
            reason: 'Repeated spam',
            type: BanType::Permanent,
        );

        self::assertNotEmpty($ban->id);
        self::assertSame('user-1', $ban->userId);
        self::assertSame('mod-1', $ban->bannedBy);
        self::assertSame('Repeated spam', $ban->reason);
        self::assertSame(BanType::Permanent, $ban->type);
        self::assertNull($ban->expiresAt);
        self::assertNull($ban->revokedAt);
        self::assertInstanceOf(DateTimeImmutable::class, $ban->createdAt);
    }

    #[Test]
    public function createWithExpiryDate(): void
    {
        $expires = new DateTimeImmutable('+7 days');

        $ban = UserBan::create(
            userId: 'user-2',
            bannedBy: 'mod-1',
            reason: 'Minor offense',
            type: BanType::Temporary,
            expiresAt: $expires,
        );

        self::assertSame(BanType::Temporary, $ban->type);
        self::assertSame($expires, $ban->expiresAt);
    }

    #[Test]
    public function isActiveReturnsTrueForPermanentBan(): void
    {
        $ban = UserBan::create(
            userId: 'user-1',
            bannedBy: 'mod-1',
            reason: 'Severe abuse',
            type: BanType::Permanent,
        );

        self::assertTrue($ban->isActive());
    }

    #[Test]
    public function isActiveReturnsTrueForTemporaryBanNotExpired(): void
    {
        $ban = UserBan::create(
            userId: 'user-1',
            bannedBy: 'mod-1',
            reason: 'Minor offense',
            type: BanType::Temporary,
            expiresAt: new DateTimeImmutable('+1 hour'),
        );

        self::assertTrue($ban->isActive());
    }

    #[Test]
    public function isActiveReturnsFalseForExpiredTemporaryBan(): void
    {
        $ban = new UserBan(
            id: 'ban-1',
            userId: 'user-1',
            bannedBy: 'mod-1',
            reason: 'Old offense',
            type: BanType::Temporary,
            expiresAt: new DateTimeImmutable('-1 hour'),
            createdAt: new DateTimeImmutable('-2 hours'),
            revokedAt: null,
        );

        self::assertFalse($ban->isActive());
    }

    #[Test]
    public function isActiveReturnsFalseForRevokedBan(): void
    {
        $ban = new UserBan(
            id: 'ban-1',
            userId: 'user-1',
            bannedBy: 'mod-1',
            reason: 'Revoked',
            type: BanType::Permanent,
            expiresAt: null,
            createdAt: new DateTimeImmutable('-1 day'),
            revokedAt: new DateTimeImmutable(),
        );

        self::assertFalse($ban->isActive());
    }

    #[Test]
    public function revokeReturnsNewInstanceWithRevokedAt(): void
    {
        $ban = UserBan::create(
            userId: 'user-1',
            bannedBy: 'mod-1',
            reason: 'Spam',
            type: BanType::Permanent,
        );

        $revoked = $ban->revoke();

        self::assertNull($ban->revokedAt);
        self::assertNotNull($revoked->revokedAt);
        self::assertFalse($revoked->isActive());
        // Original ban data is preserved
        self::assertSame($ban->id, $revoked->id);
        self::assertSame($ban->userId, $revoked->userId);
        self::assertSame($ban->reason, $revoked->reason);
    }

    #[Test]
    public function isActiveReturnsTrueWhenExpiresAtIsNullAndTypeIsTemporary(): void
    {
        // Edge case: temporary ban with no expiry set should still be active
        $ban = new UserBan(
            id: 'ban-1',
            userId: 'user-1',
            bannedBy: 'mod-1',
            reason: 'Indefinite temp',
            type: BanType::Temporary,
            expiresAt: null,
            createdAt: new DateTimeImmutable(),
            revokedAt: null,
        );

        self::assertTrue($ban->isActive());
    }
}
