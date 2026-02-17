<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\InMemoryTotpReplayGuard;
use Pulsar\Auth\TwoFactor\TwoFactorPurpose;

final class InMemoryTotpReplayGuardTest extends TestCase
{
    #[Test]
    public function mark_used_succeeds_on_first_use(): void
    {
        $guard = new InMemoryTotpReplayGuard();

        $result = $guard->markUsed('user-1', TwoFactorPurpose::Login, 100, 1700000000);

        self::assertTrue($result);
    }

    #[Test]
    public function mark_used_rejects_duplicate_time_step(): void
    {
        $guard = new InMemoryTotpReplayGuard();

        $guard->markUsed('user-1', TwoFactorPurpose::Login, 100, 1700000000);
        $result = $guard->markUsed('user-1', TwoFactorPurpose::Login, 100, 1700000001);

        self::assertFalse($result);
    }

    #[Test]
    public function different_purposes_are_independent(): void
    {
        $guard = new InMemoryTotpReplayGuard();

        $guard->markUsed('user-1', TwoFactorPurpose::Login, 100, 1700000000);
        $result = $guard->markUsed('user-1', TwoFactorPurpose::StepUp, 100, 1700000000);

        self::assertTrue($result);
    }

    #[Test]
    public function different_identities_are_independent(): void
    {
        $guard = new InMemoryTotpReplayGuard();

        $guard->markUsed('user-1', TwoFactorPurpose::Login, 100, 1700000000);
        $result = $guard->markUsed('user-2', TwoFactorPurpose::Login, 100, 1700000000);

        self::assertTrue($result);
    }

    #[Test]
    public function different_time_steps_are_independent(): void
    {
        $guard = new InMemoryTotpReplayGuard();

        $guard->markUsed('user-1', TwoFactorPurpose::Login, 100, 1700000000);
        $result = $guard->markUsed('user-1', TwoFactorPurpose::Login, 101, 1700000000);

        self::assertTrue($result);
    }

    #[Test]
    public function expired_entries_are_pruned(): void
    {
        $guard = new InMemoryTotpReplayGuard(ttl: 60);

        $guard->markUsed('user-1', TwoFactorPurpose::Login, 100, 1700000000);

        // 61 seconds later the entry should be expired and pruned
        $result = $guard->markUsed('user-1', TwoFactorPurpose::Login, 100, 1700000061);

        self::assertTrue($result);
    }

    #[Test]
    public function entries_within_ttl_are_not_pruned(): void
    {
        $guard = new InMemoryTotpReplayGuard(ttl: 60);

        $guard->markUsed('user-1', TwoFactorPurpose::Login, 100, 1700000000);

        // 59 seconds later the entry should still be present
        $result = $guard->markUsed('user-1', TwoFactorPurpose::Login, 100, 1700000059);

        self::assertFalse($result);
    }
}
