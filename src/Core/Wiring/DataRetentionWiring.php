<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\SchedulerConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\DataProtection\DataProtectionConfig;
use Pulsar\DataProtection\DataPurgeJob;
use Pulsar\DataProtection\DataPurgeOrchestrator;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Scheduler\Exception\SchedulerException;
use Pulsar\Scheduler\JobRegistry;
use Pulsar\Scheduler\Schedule;

use function sprintf;

/**
 * Puts the declared retention policies on a schedule.
 *
 * {@see \Pulsar\Core\Wiring\SecurityWiring} builds the {@see DataPurgeOrchestrator}
 * out of `config/data_protection.php` — every category, its retention period and
 * its legal basis — and until this wiring existed, nothing ever asked it to run.
 * No console command resolved it, no job held it. Retention was declared in
 * configuration and executed by nothing, which is worse than declaring no
 * retention at all: `config/data_protection.php` reads as a storage-limitation
 * control, an auditor reads it as one, and the records it names were never
 * deleted.
 *
 * The wiring runs after {@see SchedulerWiring} because it registers into the job
 * registry that wiring builds, and after {@see SecurityWiring} because that is
 * where the orchestrator comes from. Both are guards rather than assumptions: a
 * deployment with the scheduler switched off simply keeps `pulsar data:purge`,
 * and one with no orchestrator has nothing to schedule.
 *
 * Registration alone deletes nothing. The job fires only when the operator runs
 * `pulsar scheduler:tick`, and it honours `purge.dry_run`, so a deployment can
 * watch the schedule count for as long as it wants before letting it act.
 */
#[Internal(reason: 'Composition root wiring')]
final readonly class DataRetentionWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        if (!$container->has(JobRegistry::class) || !$container->has(DataPurgeOrchestrator::class)) {
            return;
        }

        $repository = $configManager->repository();

        $config = $repository->has(DataProtectionConfig::class)
            ? $repository->get(DataProtectionConfig::class)
            : new DataProtectionConfig();

        /** @var DataProtectionConfig $config */
        $expression = $config->purge->schedule;

        // An empty expression is the operator saying "no automatic deletion" in a
        // diff someone can read. Anything else is a schedule, and a malformed one
        // must not pass silently: a cron expression that never matches would leave
        // retention unexecuted while the job list showed it registered.
        if ($expression === '') {
            return;
        }

        /** @var JobRegistry $registry */
        $registry = $container->get(JobRegistry::class);
        /** @var DataPurgeOrchestrator $orchestrator */
        $orchestrator = $container->get(DataPurgeOrchestrator::class);

        $job = new DataPurgeJob($orchestrator, new Schedule($expression, $this->timezone($container)));

        try {
            $registry->register($job);
        } catch (SchedulerException) {
            // Already registered — an application that scheduled its own purge job
            // under the same name keeps it. Overwriting an operator's job with the
            // framework default would be the framework deciding what gets deleted.
            return;
        }

        $this->announce($container, $expression, $config->purge->dryRun);
    }

    /**
     * The scheduler's own timezone, so a purge scheduled for 03:00 happens at the
     * 03:00 every other job in this deployment means. SchedulerWiring binds the
     * config; UTC is the same default {@see Schedule} uses when nothing is bound.
     */
    private function timezone(ContainerInterface $container): string
    {
        if (!$container->has(SchedulerConfig::class)) {
            return 'UTC';
        }

        /** @var SchedulerConfig $scheduler */
        $scheduler = $container->get(SchedulerConfig::class);

        return $scheduler->timezone;
    }

    /**
     * Note that scheduled deletion is armed.
     *
     * Debug rather than info because this wiring runs on every request under
     * PHP-FPM, and a per-request line about a daily job is noise that trains
     * operators to ignore the log. The operator-facing answer to "is retention
     * scheduled?" is `pulsar scheduler:list`, which now lists the job.
     */
    private function announce(ContainerInterface $container, string $expression, bool $dryRun): void
    {
        if (!$container->has(LoggerInterface::class)) {
            return;
        }

        /** @var LoggerInterface $logger */
        $logger = $container->get(LoggerInterface::class);

        $logger->debug(sprintf(
            'data protection: retention job "%s" scheduled (%s)%s. Runs on `pulsar scheduler:tick`; '
            . 'set purge.schedule to "" in config/data_protection.php to keep policies without '
            . 'automatic deletion.',
            DataPurgeJob::NAME,
            $expression,
            $dryRun ? ', dry-run mode — counts only' : '',
        ));
    }
}
