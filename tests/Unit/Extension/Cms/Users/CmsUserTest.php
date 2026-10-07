<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Users;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Extension\Cms\Users\CmsUser;

#[CoversClass(CmsUser::class)]
final class CmsUserTest extends TestCase
{
    #[Test]
    public function constructorActiveUser(): void
    {
        $lastActive = new DateTimeImmutable('2025-03-07T09:30:00+00:00');
        $created = new DateTimeImmutable('2024-01-15T08:00:00+00:00');

        $user = new CmsUser(
            id: 'user-01',
            tenantId: 'tenant-01',
            displayName: 'Jane Editor',
            email: 'jane@example.com',
            roles: ['cms.editor', 'cms.media_manager'],
            twoFactorStatus: TwoFactorStatus::Verified,
            contentCount: 42,
            commentCount: 15,
            lastActiveAt: $lastActive,
            createdAt: $created,
            isLocked: false,
        );

        self::assertSame('user-01', $user->id);
        self::assertSame('tenant-01', $user->tenantId);
        self::assertSame('Jane Editor', $user->displayName);
        self::assertSame('jane@example.com', $user->email);
        self::assertCount(2, $user->roles);
        self::assertContains('cms.editor', $user->roles);
        self::assertSame(TwoFactorStatus::Verified, $user->twoFactorStatus);
        self::assertSame(42, $user->contentCount);
        self::assertSame(15, $user->commentCount);
        self::assertSame($lastActive, $user->lastActiveAt);
        self::assertFalse($user->isLocked);
    }

    #[Test]
    public function constructorLockedUserWithoutActivity(): void
    {
        $user = new CmsUser(
            id: 'user-02',
            tenantId: null,
            displayName: 'Inactive User',
            email: null,
            roles: [],
            twoFactorStatus: TwoFactorStatus::Disabled,
            contentCount: 0,
            commentCount: 0,
            lastActiveAt: null,
            createdAt: new DateTimeImmutable(),
            isLocked: true,
        );

        self::assertNull($user->tenantId);
        self::assertNull($user->email);
        self::assertSame([], $user->roles);
        self::assertSame(TwoFactorStatus::Disabled, $user->twoFactorStatus);
        self::assertNull($user->lastActiveAt);
        self::assertTrue($user->isLocked);
    }
}
