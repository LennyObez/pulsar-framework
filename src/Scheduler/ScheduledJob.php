<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

use Closure;
use DateTimeImmutable;
use Override;
use Pulsar\Api\Api;
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
 * @api
 */
#[Api(since: '1.0.0')]
final class ScheduledJob implements JobInterface
{
    /** @var array<string, int> In-memory overlap tracking: job name => lock timestamp */
    private static array $runningJobs = [];

    /**
     * @param Closure(JobContext): ?string $callback
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
    ) {}

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

    #[Override]
    public function execute(JobContext $context): JobResult
    {
        if ($this->preventOverlap && $this->isOverlapping()) {
            return JobResult::skipped($this->name);
        }

        if ($this->preventOverlap) {
            $this->acquireLock();
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
            if ($this->preventOverlap) {
                $this->releaseLock();
            }
        }
    }

    /**
     * Check if this job is currently running (overlap detection).
     */
    private function isOverlapping(): bool
    {
        if (!isset(self::$runningJobs[$this->name])) {
            return false;
        }

        $lockTime = self::$runningJobs[$this->name];
        $expirySeconds = $this->overlapExpiresAfter * 60;

        // Release stale lock
        if ((time() - $lockTime) > $expirySeconds) {
            unset(self::$runningJobs[$this->name]);
            return false;
        }

        return true;
    }

    private function acquireLock(): void
    {
        self::$runningJobs[$this->name] = time();
    }

    private function releaseLock(): void
    {
        unset(self::$runningJobs[$this->name]);
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

    /**
     * Reset all overlap locks (for testing).
     */
    public static function resetLocks(): void
    {
        self::$runningJobs = [];
    }
}
