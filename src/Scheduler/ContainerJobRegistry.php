<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;

/**
 * Binds {@see JobRegistryInterface} to the concrete {@see JobRegistry}.
 *
 * The interface has been public API since 1.0.0 and two bundled extensions call
 * it — health-status schedules its snapshot, analytics its visitor-salt purge,
 * which is a GDPR retention control. Nothing implemented it and nothing bound
 * it, so both `has(JobRegistryInterface::class)` guards were false and both
 * extensions skipped their scheduling in silence.
 *
 * The two contracts differ on purpose: the interface registers a class name and
 * a schedule, the registry stores job instances. {@see ContainerResolvedJob}
 * carries one to the other without resolving anything up front.
 */
#[Internal]
final readonly class ContainerJobRegistry implements JobRegistryInterface
{
    public function __construct(
        private JobRegistry $registry,
        private ContainerInterface $container,
    ) {}

    public function register(string $jobClass, Schedule $schedule): void
    {
        $this->registry->register(new ContainerResolvedJob($jobClass, $schedule, $this->container));
    }
}
