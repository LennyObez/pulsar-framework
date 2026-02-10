<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Badge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Badge\UserBadge;
use Pulsar\Extension\Forum\Domain\Badge;

#[CoversClass(UserBadge::class)]
final class UserBadgeTest extends TestCase
{
    #[Test]
    public function awardCreatesUserBadge(): void
    {
        $badge = UserBadge::award(id: 'ub-001', userId: 'user-001', badge: Badge::FirstPost, tenantId: 'tenant-001');
        self::assertSame('ub-001', $badge->id);
        self::assertSame('tenant-001', $badge->tenantId);
        self::assertSame('user-001', $badge->userId);
        self::assertSame(Badge::FirstPost, $badge->badge);
        self::assertNotNull($badge->awardedAt);
    }

    #[Test]
    public function awardWithoutTenant(): void
    {
        $badge = UserBadge::award(id: 'ub-001', userId: 'user-001', badge: Badge::Solver);
        self::assertNull($badge->tenantId);
    }

    #[Test]
    public function awardDifferentBadgeTypes(): void
    {
        $helpful = UserBadge::award('ub-001', 'u-001', Badge::Helpful);
        $bugHunter = UserBadge::award('ub-002', 'u-001', Badge::BugHunter);
        $contributor = UserBadge::award('ub-003', 'u-001', Badge::Contributor);
        self::assertSame(Badge::Helpful, $helpful->badge);
        self::assertSame(Badge::BugHunter, $bugHunter->badge);
        self::assertSame(Badge::Contributor, $contributor->badge);
    }
}
