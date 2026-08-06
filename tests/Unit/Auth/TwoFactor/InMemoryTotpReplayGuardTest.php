<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\InMemoryTotpReplayGuard;

final class InMemoryTotpReplayGuardTest extends TestCase
{
    #[Test]
    public function mark_used_succeeds_on_first_use(): void
    {
        $guard = new InMemoryTotpReplayGuard();

        $result = $guard->markUsed('user-1', 100, 1700000000);

        self::assertTrue($result);
    }

    #[Test]
    public function mark_used_rejects_duplicate_time_step(): void
    {
        $guard = new InMemoryTotpReplayGuard();

        $guard->markUsed('user-1', 100, 1700000000);
        $result = $guard->markUsed('user-1', 100, 1700000001);

        self::assertFalse($result);
    }

    #[Test]
    public function different_identities_are_independent(): void
    {
        $guard = new InMemoryTotpReplayGuard();

        $guard->markUsed('user-1', 100, 1700000000);
        $result = $guard->markUsed('user-2', 100, 1700000000);

        self::assertTrue($result);
    }

    #[Test]
    public function different_time_steps_are_independent(): void
    {
        $guard = new InMemoryTotpReplayGuard();

        $guard->markUsed('user-1', 100, 1700000000);
        $result = $guard->markUsed('user-1', 101, 1700000000);

        self::assertTrue($result);
    }

    /**
     * The key omits the purpose, so the Login/Setup/StepUp sequence that a
     * purpose-keyed guard sold three times now sells once (ASVS 2.8.4).
     */
    #[Test]
    public function one_time_step_is_redeemable_once_however_many_flows_ask(): void
    {
        $guard = new InMemoryTotpReplayGuard();

        self::assertTrue($guard->markUsed('user-1', 100, 1700000000));
        self::assertFalse($guard->markUsed('user-1', 100, 1700000000));
        self::assertFalse($guard->markUsed('user-1', 100, 1700000000));
    }

    #[Test]
    public function retention_outlasts_the_acceptance_envelope(): void
    {
        // 30 s period, +/-1 step: the verifier accepts the code for 90 s.
        $guard = new InMemoryTotpReplayGuard();

        self::assertTrue($guard->markUsed('user-1', 100, 1700000000));
        self::assertFalse($guard->markUsed('user-1', 100, 1700000089));
    }

    #[Test]
    public function retention_scales_with_the_code_period(): void
    {
        // 60 s period, +/-1 step: a 180 s envelope.
        $guard = new InMemoryTotpReplayGuard(codePeriod: 60, verificationWindow: 1);

        self::assertTrue($guard->markUsed('user-1', 100, 1700000000));
        self::assertFalse($guard->markUsed('user-1', 100, 1700000179));
    }

    #[Test]
    public function retention_scales_with_the_verification_window(): void
    {
        // 30 s period, +/-2 steps: a 150 s envelope.
        $guard = new InMemoryTotpReplayGuard(codePeriod: 30, verificationWindow: 2);

        self::assertTrue($guard->markUsed('user-1', 100, 1700000000));
        self::assertFalse($guard->markUsed('user-1', 100, 1700000149));
    }

    #[Test]
    public function entries_expire_once_the_retention_window_closes(): void
    {
        // 90 s envelope plus one period of clock-skew margin.
        $guard = new InMemoryTotpReplayGuard();

        self::assertTrue($guard->markUsed('user-1', 100, 1700000000));
        self::assertFalse($guard->markUsed('user-1', 100, 1700000119));
        self::assertTrue($guard->markUsed('user-1', 100, 1700000120));
    }
}
