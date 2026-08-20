<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Scheduler\Exception\SchedulerException;

use function is_callable;
use function sprintf;

/**
 * A scheduled job named by class, resolved from the container when it runs.
 *
 * {@see JobRegistryInterface} registers a class name and a schedule, while
 * {@see JobRegistry} stores {@see JobInterface} instances. This is the bridge.
 *
 * Resolution is deferred to {@see self::execute()} on purpose: registration
 * happens during extension boot, on every request, whereas a scheduled job only
 * ever runs from `schedule:run`. Constructing it at registration time would drag
 * its whole dependency graph — repositories, stores, key managers — into every
 * HTTP request that never runs it.
 *
 * The schedule passed to `register()` wins over any the class declares for
 * itself: it is the one thing the caller stated explicitly at the call site.
 *
 * Two job shapes are accepted, because both are already in use: a class
 * implementing {@see JobInterface}, and a plain invokable.
 */
#[Internal]
final readonly class ContainerResolvedJob implements JobInterface
{
    /**
     * @param class-string $jobClass
     */
    public function __construct(
        private string $jobClass,
        private Schedule $schedule,
        private ContainerInterface $container,
    ) {}

    public function getName(): string
    {
        // The class name, so the identity is unique across extensions and needs
        // no resolution — the registry rejects duplicates at registration time,
        // long before the job is ever built.
        return $this->jobClass;
    }

    public function getSchedule(): Schedule
    {
        return $this->schedule;
    }

    public function getDescription(): string
    {
        return sprintf('Scheduled job %s', $this->jobClass);
    }

    public function execute(JobContext $context): JobResult
    {
        /** @var mixed $job */
        $job = $this->container->get($this->jobClass);

        if ($job instanceof JobInterface) {
            return $job->execute($context);
        }

        if (is_callable($job)) {
            $job();

            return JobResult::success($this->getName(), $context->startedAt);
        }

        throw SchedulerException::jobNotRunnable($this->jobClass);
    }
}
