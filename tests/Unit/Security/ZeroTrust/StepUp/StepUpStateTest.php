<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\StepUp;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ZeroTrust\StepUp\StepUpConfig;
use Pulsar\Security\ZeroTrust\StepUp\StepUpState;

#[CoversClass(StepUpState::class)]
final class StepUpStateTest extends TestCase
{
    #[Test]
    public function defaultStateHasZeroAttempts(): void
    {
        $state = new StepUpState(identityId: 'user-1');

        self::assertSame('user-1', $state->identityId);
        self::assertSame(0, $state->attemptCount);
        self::assertNull($state->lastAttemptAt);
        self::assertNull($state->lockedUntil);
    }

    #[Test]
    public function isLockedOutReturnsFalseByDefault(): void
    {
        $state = new StepUpState(identityId: 'user-1');

        self::assertFalse($state->isLockedOut());
    }

    #[Test]
    public function isLockedOutReturnsTrueWhenLockoutActive(): void
    {
        $future = new DateTimeImmutable('+1 hour');
        $state = new StepUpState(
            identityId: 'user-1',
            lockedUntil: $future,
        );

        self::assertTrue($state->isLockedOut());
    }

    #[Test]
    public function isLockedOutReturnsFalseWhenLockoutExpired(): void
    {
        $past = new DateTimeImmutable('-1 hour');
        $state = new StepUpState(
            identityId: 'user-1',
            lockedUntil: $past,
        );

        self::assertFalse($state->isLockedOut());
    }

    #[Test]
    public function isCoolingDownReturnsFalseWhenNoCooldown(): void
    {
        $config = new StepUpConfig(cooldownSeconds: 0);
        $state = new StepUpState(identityId: 'user-1');

        self::assertFalse($state->isCoolingDown($config));
    }

    #[Test]
    public function isCoolingDownReturnsFalseWhenNoLastAttempt(): void
    {
        $config = new StepUpConfig(cooldownSeconds: 30);
        $state = new StepUpState(identityId: 'user-1');

        self::assertFalse($state->isCoolingDown($config));
    }

    #[Test]
    public function isCoolingDownReturnsTrueWithinCooldownWindow(): void
    {
        $config = new StepUpConfig(cooldownSeconds: 60);
        $now = new DateTimeImmutable();
        $recentAttempt = $now->modify('-10 seconds');

        $state = new StepUpState(
            identityId: 'user-1',
            lastAttemptAt: $recentAttempt,
        );

        self::assertTrue($state->isCoolingDown($config, $now));
    }

    #[Test]
    public function isCoolingDownReturnsFalseAfterCooldownExpires(): void
    {
        $config = new StepUpConfig(cooldownSeconds: 30);
        $now = new DateTimeImmutable();
        $oldAttempt = $now->modify('-60 seconds');

        $state = new StepUpState(
            identityId: 'user-1',
            lastAttemptAt: $oldAttempt,
        );

        self::assertFalse($state->isCoolingDown($config, $now));
    }

    #[Test]
    public function recordAttemptIncrementsCount(): void
    {
        $config = new StepUpConfig(maxAttempts: 5);
        $now = new DateTimeImmutable();
        $state = new StepUpState(identityId: 'user-1', windowStart: $now);

        $newState = $state->recordAttempt($config, $now);

        self::assertSame(1, $newState->attemptCount);
        self::assertSame($now, $newState->lastAttemptAt);
        self::assertNull($newState->lockedUntil);
    }

    #[Test]
    public function recordAttemptDoesNotMutateOriginal(): void
    {
        $config = new StepUpConfig(maxAttempts: 5);
        $state = new StepUpState(identityId: 'user-1');

        (void) $state->recordAttempt($config);

        self::assertSame(0, $state->attemptCount);
    }

    #[Test]
    public function recordAttemptLocksOutAfterMaxAttempts(): void
    {
        $config = new StepUpConfig(maxAttempts: 3, lockoutSeconds: 900);
        $now = new DateTimeImmutable();
        $state = new StepUpState(
            identityId: 'user-1',
            attemptCount: 2,
            windowStart: $now,
        );

        $locked = $state->recordAttempt($config, $now);

        self::assertSame(3, $locked->attemptCount);
        self::assertNotNull($locked->lockedUntil);
        self::assertTrue($locked->isLockedOut($now));
    }

    #[Test]
    public function recordAttemptResetsWindowWhenExpired(): void
    {
        $config = new StepUpConfig(maxAttempts: 5, windowSeconds: 60);
        $now = new DateTimeImmutable();
        $oldWindowStart = $now->modify('-120 seconds');

        $state = new StepUpState(
            identityId: 'user-1',
            attemptCount: 4,
            windowStart: $oldWindowStart,
        );

        $newState = $state->recordAttempt($config, $now);

        // Window reset, so count starts from 1
        self::assertSame(1, $newState->attemptCount);
        self::assertNull($newState->lockedUntil);
    }

    #[Test]
    public function resetReturnsCleanState(): void
    {
        $state = new StepUpState(
            identityId: 'user-1',
            attemptCount: 5,
            lastAttemptAt: new DateTimeImmutable(),
            lockedUntil: new DateTimeImmutable('+1 hour'),
        );

        $reset = $state->reset();

        self::assertSame('user-1', $reset->identityId);
        self::assertSame(0, $reset->attemptCount);
        self::assertNull($reset->lastAttemptAt);
        self::assertNull($reset->lockedUntil);
    }
}
