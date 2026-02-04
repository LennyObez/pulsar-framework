<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

use Closure;
use DateTimeImmutable;
use Throwable;

/**
 * Closure-based scheduled job.
 */
final readonly class CallbackJob implements JobInterface
{
    /**
     * @param Closure(JobContext): ?string $callback
     */
    public function __construct(
        private string $name,
        private Schedule $schedule,
        private Closure $callback,
        private string $description = '',
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getSchedule(): Schedule
    {
        return $this->schedule;
    }

    public function execute(JobContext $context): JobResult
    {
        $startedAt = new DateTimeImmutable();

        try {
            $output = ($this->callback)($context);

            return JobResult::success($this->name, $startedAt, $output ?? '');
        } catch (Throwable $e) {
            return JobResult::failure($this->name, $startedAt, $e);
        }
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}
