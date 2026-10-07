<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler;

use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Pulsar\Cache\Application\Lock\FilesystemLock;
use Pulsar\Cache\Application\Lock\LockHandle;
use Pulsar\Cache\Application\Lock\LockInterface;
use Pulsar\Scheduler\Exception\SchedulerException;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobStatus;
use Pulsar\Scheduler\Schedule;
use Pulsar\Scheduler\ScheduleBuilder;
use Pulsar\Scheduler\ScheduledJob;
use RuntimeException;

use function bin2hex;
use function is_dir;
use function random_bytes;
use function rmdir;
use function scandir;
use function str_contains;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * `withoutOverlapping()` is now a lock that can be observed held.
 *
 * The old implementation tracked overlap in a `private static array` released
 * in the same `execute()` call that took it. Nothing outside that one call
 * frame could ever see it: scheduler ticks are separate `cron` processes, so
 * the flag was always gone before the next run looked. An operator who called
 * the method believed concurrent runs were impossible; nothing enforced it.
 *
 * The test that matters here is {@see aRunHeldByAnotherProcessIsSkipped}: it
 * takes the job's lock the way a second scheduler host would, then runs the
 * job. On the old code the job ran anyway.
 */
#[CoversClass(ScheduledJob::class)]
#[CoversClass(ScheduleBuilder::class)]
#[CoversClass(SchedulerException::class)]
final class ScheduledJobOverlapLockTest extends TestCase
{
    private string $lockDirectory = '';

    protected function setUp(): void
    {
        $this->lockDirectory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'pulsar_sched_lock_' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->lockDirectory)) {
            return;
        }

        $entries = scandir($this->lockDirectory);

        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($this->lockDirectory . DIRECTORY_SEPARATOR . $entry);
                }
            }
        }

        @rmdir($this->lockDirectory);
    }

    private function lock(): FilesystemLock
    {
        return new FilesystemLock($this->lockDirectory);
    }

    private function context(?LoggerInterface $logger = null): JobContext
    {
        return new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
            logger: $logger,
        );
    }

    /**
     * @param Closure(JobContext): ?string $callback
     */
    private function job(LockInterface $lock, Closure $callback, int $expiresAfterMinutes = 1440): ScheduledJob
    {
        return ScheduleBuilder::job('nightly-reconciliation', $callback)
            ->daily()
            ->withoutOverlapping($lock, $expiresAfterMinutes)
            ->build();
    }

    #[Test]
    public function aRunHeldByAnotherProcessIsSkipped(): void
    {
        $ran = false;
        $job = $this->job($this->lock(), static function () use (&$ran): string {
            $ran = true;

            return 'reconciled';
        });

        // A second scheduler host is already inside this job: it holds the same
        // lock resource through its own lock over the shared directory. Nothing
        // in this process knows about that run.
        $competitor = $this->lock();
        $held = $competitor->acquire($job->lockResource(), ttlSeconds: 300, timeoutMs: 0);

        try {
            $result = $job->execute($this->context());
        } finally {
            $competitor->release($held);
        }

        self::assertSame(JobStatus::Skipped, $result->status, 'the job must not run while another run holds the lock');
        self::assertFalse($ran, 'the callback must not have executed');
    }

    #[Test]
    public function theLockIsReleasedSoTheNextTickCanRun(): void
    {
        $runs = 0;
        $job = $this->job($this->lock(), static function () use (&$runs): string {
            $runs++;

            return 'ok';
        });

        self::assertSame(JobStatus::Success, $job->execute($this->context())->status);
        self::assertSame(JobStatus::Success, $job->execute($this->context())->status);
        self::assertSame(2, $runs);

        // And the resource is genuinely free afterwards, not merely unobserved.
        $prober = $this->lock();
        $prober->release($prober->acquire($job->lockResource(), ttlSeconds: 5, timeoutMs: 0));
    }

    #[Test]
    public function theLockIsReleasedWhenTheJobThrows(): void
    {
        $job = $this->job($this->lock(), static function (): never {
            throw new RuntimeException('boom');
        });

        self::assertSame(JobStatus::Failure, $job->execute($this->context())->status);

        // A crashed run that kept the lock would block the job until the expiry
        // elapsed — up to a day on the default setting.
        $prober = $this->lock();
        $prober->release($prober->acquire($job->lockResource(), ttlSeconds: 5, timeoutMs: 0));
    }

    #[Test]
    public function twoJobsOfTheSameNameContendForOneResource(): void
    {
        // Two ScheduledJob objects, as two hosts would build them: the lock
        // resource is derived from the job name, not from object identity.
        $lock = $this->lock();
        $first = $this->job($lock, static fn(): string => 'first');
        $second = $this->job($this->lock(), static fn(): string => 'second');

        self::assertSame($first->lockResource(), $second->lockResource());

        $held = $lock->acquire($first->lockResource(), ttlSeconds: 300, timeoutMs: 0);

        try {
            self::assertSame(JobStatus::Skipped, $second->execute($this->context())->status);
        } finally {
            $lock->release($held);
        }
    }

    #[Test]
    public function theLockResourceIsNamespacedAwayFromOtherLockUsers(): void
    {
        $job = $this->job($this->lock(), static fn(): string => 'ran');

        self::assertSame('pulsar:scheduler:job:nightly-reconciliation', $job->lockResource());
    }

    #[Test]
    public function aJobWithoutOverlapPreventionTakesNoLock(): void
    {
        $job = ScheduleBuilder::job('unguarded', static fn(): string => 'ran')->daily()->build();

        self::assertFalse($job->preventsOverlap());
        self::assertSame(JobStatus::Success, $job->execute($this->context())->status);
    }

    #[Test]
    public function overlapPreventionWithoutALockIsRefused(): void
    {
        $this->expectException(SchedulerException::class);
        $this->expectExceptionMessageMatches('/requests overlap prevention without a lock/');

        new ScheduledJob(
            name: 'promises-nothing',
            schedule: Schedule::daily(),
            callback: static fn(): string => 'ran',
            preventOverlap: true,
        );
    }

    #[Test]
    public function anExpiryThatCannotOutliveTheRunIsRefused(): void
    {
        // A zero expiry writes a lock that is stale the instant it is taken, so
        // every competing run finds it free: overlap prevention in name only.
        $this->expectException(SchedulerException::class);
        $this->expectExceptionMessageMatches('/must be at least 1 minute/');

        $this->job($this->lock(), static fn(): string => 'ran', expiresAfterMinutes: 0);
    }

    #[Test]
    public function aLockThatNoLongerHonoursTheReleaseIsReported(): void
    {
        // release() returning false means the lock expired while the job was
        // still running and may now be held by a second copy of this job. The
        // operator asked for that to be impossible, so it is said out loud.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::callback(
                static fn(string $message): bool => str_contains($message, 'could not release its overlap lock')
                    && str_contains($message, 'nightly-reconciliation'),
            ));

        $job = $this->job(new ExpiringUnderTheRunLock(), static fn(): string => 'ran');

        $result = $job->execute($this->context($logger));

        // The run itself still succeeded — the warning is about the guarantee,
        // not about the work.
        self::assertSame(JobStatus::Success, $result->status);
    }
}

/**
 * A lock that grants acquisition and then refuses to acknowledge the release,
 * which is what every {@see LockInterface} implementation does once the handle
 * it was given has outlived its TTL.
 *
 * @internal
 */
final class ExpiringUnderTheRunLock implements LockInterface
{
    public function acquire(string $resource, int $ttlSeconds = 30, int $timeoutMs = 0): LockHandle
    {
        if ($resource === '') {
            throw LockAcquisitionException::unavailable($resource, 'empty resource');
        }

        return new LockHandle(
            resource: $resource,
            token: 'expired-under-the-run',
            acquiredAt: 0.0,
            ttlSeconds: $ttlSeconds,
        );
    }

    public function release(LockHandle $handle): bool
    {
        return false;
    }

    public function refresh(LockHandle $handle, int $ttlSeconds = 30): bool
    {
        return false;
    }
}
