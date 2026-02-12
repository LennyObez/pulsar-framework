<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Badge;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Badge\UserBadge;
use Pulsar\Extension\Forum\Domain\Badge;

final class UserBadgeTest extends TestCase
{
    #[Test]
    public function awardCreatesUserBadge(): void
    {
        $userBadge = UserBadge::award(
            id: 'ub-1',
            userId: 'user-1',
            badge: Badge::FirstPost,
        );

        self::assertSame('ub-1', $userBadge->id);
        self::assertNull($userBadge->tenantId);
        self::assertSame('user-1', $userBadge->userId);
        self::assertSame(Badge::FirstPost, $userBadge->badge);
        self::assertNotNull($userBadge->awardedAt);
    }

    #[Test]
    public function awardWithTenantId(): void
    {
        $userBadge = UserBadge::award(
            id: 'ub-1',
            userId: 'user-1',
            badge: Badge::Helpful,
            tenantId: 'tenant-1',
        );

        self::assertSame('tenant-1', $userBadge->tenantId);
    }

    #[Test]
    public function awardDifferentBadges(): void
    {
        $solver = UserBadge::award('ub-1', 'user-1', Badge::Solver);
        $hunter = UserBadge::award('ub-2', 'user-1', Badge::BugHunter);

        self::assertSame(Badge::Solver, $solver->badge);
        self::assertSame(Badge::BugHunter, $hunter->badge);
    }
}
