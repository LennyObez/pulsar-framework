<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

use Closure;
use DateTimeImmutable;
use Override;
use Throwable;

/**
 * Closure-based scheduled job.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
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

    #[Override]
    public function getDescription(): string
    {
        return $this->description;
    }
}
