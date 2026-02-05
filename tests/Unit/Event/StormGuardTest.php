<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\StormProtectionConfig;
use Pulsar\Event\Exception\EventException;
use Pulsar\Event\Internal\StormGuard;

#[CoversClass(StormGuard::class)]
final class StormGuardTest extends TestCase
{
    #[Test]
    public function test_enter_and_leave_track_depth(): void
    {
        $guard = new StormGuard(new StormProtectionConfig());

        self::assertSame(0, $guard->currentDepth());

        $guard->enter('App\\Event\\A', null);
        self::assertSame(1, $guard->currentDepth());

        $guard->enter('App\\Event\\B', null);
        self::assertSame(2, $guard->currentDepth());

        $guard->leave();
        self::assertSame(1, $guard->currentDepth());

        $guard->leave();
        self::assertSame(0, $guard->currentDepth());
    }

    #[Test]
    public function test_enter_tracks_dispatch_chain(): void
    {
        $guard = new StormGuard(new StormProtectionConfig());

        $guard->enter('App\\Event\\A', null);
        $guard->enter('App\\Event\\B', null);

        self::assertSame(['App\\Event\\A', 'App\\Event\\B'], $guard->dispatchChain());
    }

    #[Test]
    public function test_throws_when_maxDepth_exceeded(): void
    {
        $guard = new StormGuard(new StormProtectionConfig(maxDepth: 2));

        $guard->enter('App\\Event\\A', null);
        $guard->enter('App\\Event\\B', null);

        $this->expectException(EventException::class);
        $this->expectExceptionMessageMatches('/storm detected/i');

        $guard->enter('App\\Event\\C', null);
    }

    #[Test]
    public function test_override_maxDepth_allows_deeper_dispatch(): void
    {
        $guard = new StormGuard(new StormProtectionConfig(maxDepth: 2));

        $guard->enter('App\\Event\\A', 5);
        $guard->enter('App\\Event\\B', 5);
        $guard->enter('App\\Event\\C', 5);

        self::assertSame(3, $guard->currentDepth());

        $guard->leave();
        $guard->leave();
        $guard->leave();
    }

    #[Test]
    public function test_loop_detection_throws_when_max_repeats_reached(): void
    {
        $guard = new StormGuard(new StormProtectionConfig(maxDepth: 32, maxRepeatsPerEvent: 3));

        $guard->enter('App\\Event\\A', null);
        $guard->enter('App\\Event\\B', null);
        $guard->enter('App\\Event\\A', null);

        $this->expectException(EventException::class);
        $this->expectExceptionMessageMatches('/loop detected/i');

        $guard->enter('App\\Event\\A', null);
    }

    #[Test]
    public function test_loop_detection_allows_under_threshold(): void
    {
        $guard = new StormGuard(new StormProtectionConfig(maxDepth: 32, maxRepeatsPerEvent: 3));

        $guard->enter('App\\Event\\A', null);
        $guard->enter('App\\Event\\B', null);
        $guard->enter('App\\Event\\A', null);

        // Two occurrences of A, threshold is 3 — allowed
        self::assertSame(3, $guard->currentDepth());

        $guard->leave();
        $guard->leave();
        $guard->leave();
    }

    #[Test]
    public function test_loop_detection_disabled(): void
    {
        $guard = new StormGuard(new StormProtectionConfig(maxDepth: 32, loopDetection: false, maxRepeatsPerEvent: 2));

        // With loop detection off, repeats should not throw
        $guard->enter('App\\Event\\A', null);
        $guard->enter('App\\Event\\A', null);
        $guard->enter('App\\Event\\A', null);

        self::assertSame(3, $guard->currentDepth());

        $guard->leave();
        $guard->leave();
        $guard->leave();
    }

    #[Test]
    public function test_leave_does_not_underflow(): void
    {
        $guard = new StormGuard(new StormProtectionConfig());

        $guard->leave();
        $guard->leave();

        self::assertSame(0, $guard->currentDepth());
        self::assertSame([], $guard->dispatchChain());
    }

    #[Test]
    public function test_reset_clears_state(): void
    {
        $guard = new StormGuard(new StormProtectionConfig());

        $guard->enter('App\\Event\\A', null);
        $guard->enter('App\\Event\\B', null);

        $guard->reset();

        self::assertSame(0, $guard->currentDepth());
        self::assertSame([], $guard->dispatchChain());
    }

    #[Test]
    public function test_enter_does_not_corrupt_state_on_depth_exceeded(): void
    {
        $guard = new StormGuard(new StormProtectionConfig(maxDepth: 1));

        $guard->enter('App\\Event\\A', null);

        try {
            $guard->enter('App\\Event\\B', null);
            self::fail('Expected EventException');
        } catch (EventException) {
            // State should not be corrupted by the failed enter
            self::assertSame(1, $guard->currentDepth());
            self::assertSame(['App\\Event\\A'], $guard->dispatchChain());
        }
    }

    #[Test]
    public function test_enter_does_not_corrupt_state_on_loop_detected(): void
    {
        $guard = new StormGuard(new StormProtectionConfig(maxDepth: 32, maxRepeatsPerEvent: 2));

        $guard->enter('App\\Event\\A', null);
        $guard->enter('App\\Event\\B', null);

        // Third enter with 'A' would be occurrence #2, which meets threshold of 2
        try {
            $guard->enter('App\\Event\\A', null);
            self::fail('Expected EventException');
        } catch (EventException) {
            // State should not be corrupted by the failed enter
            self::assertSame(2, $guard->currentDepth());
            self::assertSame(['App\\Event\\A', 'App\\Event\\B'], $guard->dispatchChain());
        }
    }
}
