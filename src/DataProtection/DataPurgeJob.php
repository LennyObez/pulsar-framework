<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use Override;
use Pulsar\Api\Api;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\Schedule;

use function array_map;
use function array_sum;
use function count;
use function implode;
use function sprintf;

/**
 * Executes the retention policies declared in `config/data_protection.php`.
 *
 * The policies were being read at boot, turned into
 * {@see DefaultRetentionPolicy} objects, handed to a
 * {@see DataPurgeOrchestrator} and then never run: no command resolved the
 * orchestrator and no scheduler held a job for it. Retention was declared and
 * never executed, on a framework whose compliance profile advertises retention
 * control — storage limitation (GDPR Art 5(1)(e)) is not satisfied by an
 * intention to delete.
 *
 * This job is the scheduled half of the fix; {@see \Pulsar\Console\Command\DataPurgeCommand}
 * is the on-demand half, and both drive the same orchestrator so a dry run from
 * the console shows exactly what the schedule will remove.
 *
 * Nothing is deleted unless the operator has a scheduler running: the job is only
 * registered when `config/scheduler.php` enables the scheduler, and it only fires
 * when `pulsar scheduler:tick` runs. `purge.dry_run` in
 * `config/data_protection.php` is honoured by the orchestrator, so a deployment
 * can watch the schedule count before letting it delete.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class DataPurgeJob implements JobInterface
{
    public const string NAME = 'data-protection:purge';

    public function __construct(
        private DataPurgeOrchestrator $orchestrator,
        private Schedule $schedule,
    ) {}

    #[Override]
    public function getName(): string
    {
        return self::NAME;
    }

    #[Override]
    public function getSchedule(): Schedule
    {
        return $this->schedule;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Applies every retention policy in config/data_protection.php to the data '
            . 'categories that have a purge implementation.';
    }

    #[Override]
    public function execute(JobContext $context): JobResult
    {
        $results = $this->orchestrator->purgeAll();

        if ($results === []) {
            // Not an error, and not silent either: a deployment whose retention
            // section names categories nothing can purge has a gap it should see.
            return JobResult::success(
                $this->getName(),
                $context->startedAt,
                'No retention category has both a purge implementation and a policy; nothing ran.',
            );
        }

        $total = array_sum(array_map(static fn(PurgeResult $r): int => $r->purgedCount, $results));
        $dryRun = $results[0]->dryRun;

        return JobResult::success($this->getName(), $context->startedAt, sprintf(
            'retention %s: %d record(s) across %d categor%s (%s)',
            $dryRun ? 'dry run' : 'purge',
            $total,
            count($results),
            count($results) === 1 ? 'y' : 'ies',
            implode(', ', array_map(
                static fn(PurgeResult $r): string => sprintf('%s=%d', $r->category, $r->purgedCount),
                $results,
            )),
        ));
    }
}
