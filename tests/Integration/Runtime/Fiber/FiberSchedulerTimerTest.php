<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Runtime\Fiber;

use Fiber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\Fiber\CooperativeSleep;
use Pulsar\Runtime\Fiber\FiberDelay;
use Pulsar\Runtime\Fiber\FiberScheduler;
use Pulsar\Tests\Support\RequiresUninstrumentedRuntime;
use Socket;

use function hrtime;
use function socket_close;
use function socket_create_pair;
use function usleep;

#[CoversClass(FiberScheduler::class)]
#[CoversClass(FiberDelay::class)]
#[CoversClass(CooperativeSleep::class)]
final class FiberSchedulerTimerTest extends TestCase
{
    use RequiresUninstrumentedRuntime;

    /** @var list<Socket> */
    private array $socketsToClose = [];

    protected function tearDown(): void
    {
        foreach ($this->socketsToClose as $socket) {
            @socket_close($socket);
        }
        $this->socketsToClose = [];
    }

    private function socket(): Socket
    {
        $pair = [];
        $domain = PHP_OS_FAMILY === 'Windows' ? AF_INET : AF_UNIX;
        $protocol = PHP_OS_FAMILY === 'Windows' ? SOL_TCP : 0;
        socket_create_pair($domain, SOCK_STREAM, $protocol, $pair);
        /** @var array{Socket, Socket} $pair */
        $this->socketsToClose[] = $pair[0];
        $this->socketsToClose[] = $pair[1];

        return $pair[0];
    }

    /**
     * A holder the fiber mutates. An object (not a by-ref local) whose concrete
     * class the analyser infers with writable properties, so the mutation is
     * seen across the scheduler's indirect call.
     */
    private function state(): FiberTimerState
    {
        return new FiberTimerState();
    }

    #[Test]
    public function aTimerSuspendedFiberResumesOnlyAfterItsDeadline(): void
    {
        $scheduler = new FiberScheduler();
        $state = $this->state();

        // The fiber sleeps ~40ms via a FiberDelay, then flips the flag.
        $scheduler->spawn($this->socket(), static function () use ($state): void {
            Fiber::suspend(new FiberDelay(hrtime(true) + 40_000_000));
            $state->flag = true;
        });

        // It suspended on the timer, not completed.
        self::assertSame(1, $scheduler->activeFiberCount());
        self::assertFalse($state->flag);

        // A tick before the deadline must NOT resume it.
        $scheduler->tick(0.001);
        self::assertFalse($state->flag, 'must not resume before the deadline');
        self::assertSame(1, $scheduler->activeFiberCount());

        // Past the deadline, a tick resumes it and it terminates.
        usleep(50_000);
        $scheduler->tick(0.001);
        self::assertTrue($state->flag, 'must resume once the deadline has passed');
        self::assertSame(0, $scheduler->activeFiberCount());
    }

    #[Test]
    public function cooperativeSleepYieldsToTheSchedulerInsteadOfBlocking(): void
    {
        $scheduler = new FiberScheduler();
        $state = $this->state();

        // Inside a scheduler-driven fiber, CooperativeSleep suspends (does not
        // usleep): spawn() therefore returns immediately with the fiber still
        // active, rather than blocking for the whole sleep.
        $before = hrtime(true);
        $scheduler->spawn($this->socket(), static function () use ($state): void {
            CooperativeSleep::forMilliseconds(60);
            $state->flag = true;
        });
        $spawnCostMs = (hrtime(true) - $before) / 1_000_000;

        // The two assertions below prove the claim without a clock: the fiber is
        // parked and its body has not run, which cannot both hold if spawn() had
        // blocked for the sleep. The millisecond budget is a useful extra signal
        // but it measures the profiler under coverage — 55 ms of instrumentation
        // against a 30 ms budget — so it is asserted only when nothing is recording.
        if (!$this->runtimeIsInstrumented()) {
            self::assertLessThan(30.0, $spawnCostMs, 'spawn must not block for the sleep duration');
        }
        self::assertSame(1, $scheduler->activeFiberCount(), 'the fiber is parked on its timer');
        self::assertFalse($state->flag);

        // A tick after the timer elapses resumes it.
        usleep(70_000);
        $scheduler->tick(0.001);
        self::assertTrue($state->flag);
    }

    #[Test]
    public function oneSleepingFiberDoesNotBlockAnother(): void
    {
        $scheduler = new FiberScheduler();
        $state = $this->state();

        // Fiber A sleeps 60ms; fiber B sleeps 5ms. B must finish first — proof
        // that A's sleep did not freeze the worker.
        $scheduler->spawn($this->socket(), static function () use ($state): void {
            CooperativeSleep::forMilliseconds(60);
            $state->order[] = 'A';
        });
        $scheduler->spawn($this->socket(), static function () use ($state): void {
            CooperativeSleep::forMilliseconds(5);
            $state->order[] = 'B';
        });

        // Pump the loop until both have run or a safety deadline passes.
        $deadline = hrtime(true) + 500_000_000;
        while ($scheduler->activeFiberCount() > 0 && hrtime(true) < $deadline) {
            $scheduler->tick(0.005);
        }

        self::assertSame(['B', 'A'], $state->order, 'the short sleeper finishes first; the long one did not block it');
    }

    #[Test]
    public function outsideAFiberCooperativeSleepFallsBackToBlocking(): void
    {
        // No scheduler driving, not in a fiber: it must just block briefly and
        // return (never hang waiting for a resume that will not come).
        self::assertNull(Fiber::getCurrent());

        $before = hrtime(true);
        CooperativeSleep::forMilliseconds(10);
        $elapsedMs = (hrtime(true) - $before) / 1_000_000;

        self::assertGreaterThanOrEqual(8.0, $elapsedMs, 'a real (blocking) sleep happened');
    }
}

/**
 * Mutable holder for what a fiber records — a named class (writable public
 * properties) the analyser does not assume is unchanged across the scheduler
 * call, unlike a by-reference local.
 *
 * @internal
 */
final class FiberTimerState
{
    public bool $flag = false;

    /** @var list<string> */
    public array $order = [];
}
