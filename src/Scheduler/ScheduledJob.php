<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

use Closure;
use DateTimeImmutable;
use Override;
use Pulsar\Api\Api;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Pulsar\Cache\Application\Lock\LockHandle;
use Pulsar\Cache\Application\Lock\LockInterface;
use Pulsar\Scheduler\Exception\SchedulerException;
use Throwable;

use function file_put_contents;
use function is_string;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;

use const FILE_APPEND;

/**
 * Feature-rich scheduled job with overlap prevention, output handling,
 * and maintenance mode awareness.
 *
 * Created via ScheduleBuilder::job()->build().
 *
 * ## Overlap prevention
 *
 * `withoutOverlapping()` promises that two runs of the job cannot be in flight
 * at once. Keeping that promise requires a lock that outlives the process: a
 * scheduler tick is a separate `cron` invocation, and production installations
 * run the scheduler on more than one host. A lock held in a static array is
 * gone before the next tick starts and invisible to every other host, so it can
 * never be observed held — which is why one is no longer used here and why the
 * job refuses to be constructed with `preventOverlap` and no
 * {@see LockInterface}.
 *
 * The lock is taken with a zero wait: a job that finds it held is late, not
 * queued, and reports {@see JobStatus::Skipped}. Its lifetime is the configured
 * expiry, which is the safety valve for a run that dies without releasing.
 * @api
 */
#[Api(since: '1.0.0')]
final class ScheduledJob implements JobInterface
{
    /** Prefix for the lock resource, so job names cannot collide with other lock users. */
    private const string LOCK_PREFIX = 'pulsar:scheduler:job:';

    /**
     * @param Closure(JobContext): ?string $callback
     * @param LockInterface|null $lock Required when `$preventOverlap` is true; must be
     *        shared by every process and host that runs this scheduler.
     *
     * @throws SchedulerException If overlap prevention is requested without a usable lock
     */
    public function __construct(
        private readonly string $name,
        private readonly Schedule $schedule,
        private readonly Closure $callback,
        private readonly string $description = '',
        private readonly bool $preventOverlap = false,
        private readonly int $overlapExpiresAfter = 1440,
        private readonly bool $runInMaintenanceMode = false,
        private readonly ?string $outputPath = null,
        private readonly bool $appendOutput = false,
        private readonly ?string $emailOutputTo = null,
        private readonly ?LockInterface $lock = null,
    ) {
        if (!$this->preventOverlap) {
            return;
        }

        if ($this->lock === null) {
            throw SchedulerException::overlapPreventionRequiresLock($this->name);
        }

        if ($this->overlapExpiresAfter < 1) {
            throw SchedulerException::invalidOverlapExpiry($this->name, $this->overlapExpiresAfter);
        }
    }

    #[Override]
    public function getName(): string
    {
        return $this->name;
    }

    #[Override]
    public function getSchedule(): Schedule
    {
        return $this->schedule;
    }

    #[Override]
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Whether this job should run during maintenance mode.
     */
    public function runsInMaintenanceMode(): bool
    {
        return $this->runInMaintenanceMode;
    }

    /**
     * Whether this job has overlap prevention enabled.
     *
     * True only when a lock is present to enforce it — the constructor refuses
     * any other combination, so this never reports a protection that is absent.
     */
    public function preventsOverlap(): bool
    {
        return $this->preventOverlap;
    }

    /**
     * Get the email address for output delivery, if configured.
     */
    public function emailOutputTo(): ?string
    {
        return $this->emailOutputTo;
    }

    /**
     * The lock resource this job's runs contend for.
     *
     * Derived from the job name, which {@see JobRegistry} already keeps unique.
     */
    public function lockResource(): string
    {
        return self::LOCK_PREFIX . $this->name;
    }

    #[Override]
    public function execute(JobContext $context): JobResult
    {
        $handle = null;

        if ($this->lock !== null) {
            try {
                // timeoutMs: 0 — a job that finds the lock held is late for its
                // slot, not queued behind the holder. Waiting would stack ticks
                // on top of each other, which is what overlap prevention exists
                // to avoid.
                $handle = $this->lock->acquire(
                    $this->lockResource(),
                    ttlSeconds: $this->overlapExpiresAfter * 60,
                    timeoutMs: 0,
                );
            } catch (LockAcquisitionException) {
                return JobResult::skipped($this->name);
            }
        }

        $startedAt = new DateTimeImmutable();

        try {
            $output = ($this->callback)($context);
            $outputStr = is_string($output) ? $output : '';

            $this->handleOutput($outputStr, $context);

            return JobResult::success($this->name, $startedAt, $outputStr);
        } catch (Throwable $e) {
            return JobResult::failure($this->name, $startedAt, $e);
        } finally {
            $this->releaseOverlapLock($handle, $context);
        }
    }

    /**
     * Release the overlap lock, reporting a release the lock no longer honours.
     *
     * A false return means the lock expired while the job was still running and
     * may now be held by a second copy of this job — the operator asked for
     * that to be impossible, so it is logged rather than swallowed.
     */
    private function releaseOverlapLock(?LockHandle $handle, JobContext $context): void
    {
        if ($this->lock === null || $handle === null) {
            return;
        }

        if (!$this->lock->release($handle)) {
            $context->logger?->warning(sprintf(
                'Scheduled job "%s" could not release its overlap lock "%s": the lock expired after '
                . '%d minute(s) while the job was still running, so a second run may have started. '
                . 'Raise the expiry passed to withoutOverlapping() above the job\'s worst-case runtime.',
                $this->name,
                $this->lockResource(),
                $this->overlapExpiresAfter,
            ));
        }
    }

    private function handleOutput(string $output, JobContext $context): void
    {
        if ($this->outputPath === null || $output === '') {
            return;
        }

        $flags = $this->appendOutput ? FILE_APPEND : 0;

        // Convert the native I/O warning emitted by file_put_contents() on an
        // unwritable target into a structured log entry instead of letting it
        // escape as an uncontrolled PHP warning. The handler is scoped to the
        // single call and always restored.
        set_error_handler(static fn(): bool => true);

        try {
            $written = file_put_contents($this->outputPath, $output . "\n", $flags);
        } finally {
            restore_error_handler();
        }

        if ($written === false) {
            $context->logger?->warning(sprintf(
                'Scheduled job "%s" failed to write output to "%s"',
                $this->name,
                $this->outputPath,
            ));
        }
    }
}
