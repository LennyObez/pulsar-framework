<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Runtime\Fiber;

use Fiber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Runtime\Fiber\FiberScheduler;
use RuntimeException;
use Socket;

use function socket_close;
use function socket_create_pair;

#[CoversClass(FiberScheduler::class)]
final class FiberSchedulerExceptionIsolationTest extends TestCase
{
    /** @var list<Socket> */
    private array $socketsToClose = [];

    protected function tearDown(): void
    {
        foreach ($this->socketsToClose as $socket) {
            @socket_close($socket);
        }
        $this->socketsToClose = [];
    }

    /**
     * @return array{Socket, Socket}
     */
    private function createSocketPair(): array
    {
        $pair = [];
        $domain = PHP_OS_FAMILY === 'Windows' ? AF_INET : AF_UNIX;
        $protocol = PHP_OS_FAMILY === 'Windows' ? SOL_TCP : 0;
        $result = socket_create_pair($domain, SOCK_STREAM, $protocol, $pair);
        self::assertTrue($result, 'Failed to create socket pair');

        /** @var array{Socket, Socket} $pair */
        $this->socketsToClose[] = $pair[0];
        $this->socketsToClose[] = $pair[1];

        return $pair;
    }

    #[Test]
    public function spawn_survives_fiber_that_throws_immediately(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Fiber crashed during start', $this->callback(
                static fn(array $ctx): bool => $ctx['exception'] instanceof RuntimeException
                    && $ctx['exception']->getMessage() === 'boom',
            ));

        $scheduler = new FiberScheduler(maxConcurrency: 10, logger: $logger);
        [$sockA] = $this->createSocketPair();

        $result = $scheduler->spawn($sockA, static function (Socket $socket): void {
            throw new RuntimeException('boom');
        });

        self::assertTrue($result, 'spawn should return true even when fiber crashes');
        self::assertSame(0, $scheduler->activeFiberCount(), 'crashed fiber should be removed');
    }

    #[Test]
    public function spawn_continues_after_crashing_fiber(): void
    {
        $scheduler = new FiberScheduler(maxConcurrency: 10);

        // First fiber crashes
        [$sockA] = $this->createSocketPair();
        $scheduler->spawn($sockA, static function (Socket $socket): void {
            throw new RuntimeException('crash');
        });

        // Second fiber should spawn successfully
        $completed = false;
        [$sockB] = $this->createSocketPair();
        $scheduler->spawn($sockB, static function (Socket $socket) use (&$completed): void {
            $completed = true;
        });

        self::assertTrue($completed, 'second fiber should complete after first crashes');
        self::assertSame(0, $scheduler->activeFiberCount());
    }

    #[Test]
    public function drain_survives_fiber_that_throws_after_suspend(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Fiber crashed during drain', $this->callback(
                static fn(array $ctx): bool => $ctx['exception'] instanceof RuntimeException,
            ));

        $scheduler = new FiberScheduler(maxConcurrency: 10, logger: $logger);

        // Fiber that suspends then throws on resume
        [$sockA] = $this->createSocketPair();
        $scheduler->spawn($sockA, static function (Socket $socket): void {
            Fiber::suspend();
            throw new RuntimeException('deferred boom');
        });

        self::assertSame(1, $scheduler->activeFiberCount());

        // drain resumes the fiber, which throws — scheduler should survive
        $remaining = $scheduler->drain(1.0);

        self::assertSame(0, $remaining, 'crashing fiber should be removed during drain');
        self::assertSame(0, $scheduler->activeFiberCount());
    }

    #[Test]
    public function drain_processes_healthy_fibers_after_one_crashes(): void
    {
        $scheduler = new FiberScheduler(maxConcurrency: 10);

        $healthyCompleted = false;

        // Crashing fiber
        [$sockA] = $this->createSocketPair();
        $scheduler->spawn($sockA, static function (Socket $socket): void {
            Fiber::suspend();
            throw new RuntimeException('crash');
        });

        // Healthy fiber
        [$sockB] = $this->createSocketPair();
        $scheduler->spawn($sockB, static function (Socket $socket) use (&$healthyCompleted): void {
            Fiber::suspend();
            $healthyCompleted = true;
        });

        self::assertSame(2, $scheduler->activeFiberCount());

        $remaining = $scheduler->drain(1.0);

        self::assertSame(0, $remaining);
        self::assertTrue($healthyCompleted, 'healthy fiber should complete despite other fiber crashing');
    }

    #[Test]
    public function scheduler_survives_multiple_concurrent_crashes(): void
    {
        $scheduler = new FiberScheduler(maxConcurrency: 20);
        $completedCount = 0;

        // Spawn 5 crashing fibers and 5 healthy fibers interleaved
        for ($i = 0; $i < 10; $i++) {
            [$sock] = $this->createSocketPair();

            if ($i % 2 === 0) {
                // Crashing fiber
                $scheduler->spawn($sock, static function (Socket $socket): void {
                    Fiber::suspend();
                    throw new RuntimeException('crash #' . spl_object_id($socket));
                });
            } else {
                // Healthy fiber
                $scheduler->spawn($sock, static function (Socket $socket) use (&$completedCount): void {
                    Fiber::suspend();
                    $completedCount++;
                });
            }
        }

        self::assertSame(10, $scheduler->activeFiberCount());

        $remaining = $scheduler->drain(2.0);

        self::assertSame(0, $remaining);
        self::assertSame(5, $completedCount, 'all 5 healthy fibers should complete');
    }

    #[Test]
    public function spawn_without_logger_still_survives_crash(): void
    {
        // No logger provided — should not throw
        $scheduler = new FiberScheduler(maxConcurrency: 10);
        [$sock] = $this->createSocketPair();

        $result = $scheduler->spawn($sock, static function (Socket $socket): void {
            throw new RuntimeException('no logger crash');
        });

        self::assertTrue($result);
        self::assertSame(0, $scheduler->activeFiberCount());
    }

    #[Test]
    public function capacity_is_freed_after_fiber_crash(): void
    {
        $scheduler = new FiberScheduler(maxConcurrency: 1);
        [$sockA] = $this->createSocketPair();

        // Fill capacity with a crashing fiber
        $scheduler->spawn($sockA, static function (Socket $socket): void {
            throw new RuntimeException('crash');
        });

        // Capacity should be freed since the crashing fiber was removed
        self::assertTrue($scheduler->hasCapacity());
        self::assertSame(0, $scheduler->activeFiberCount());

        // Should be able to spawn another fiber
        [$sockB] = $this->createSocketPair();
        $completed = false;
        $result = $scheduler->spawn($sockB, static function (Socket $socket) use (&$completed): void {
            $completed = true;
        });

        self::assertTrue($result);
        self::assertTrue($completed);
    }
}
