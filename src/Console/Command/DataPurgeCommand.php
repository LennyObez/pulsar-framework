<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\DataProtection\DataPurgeOrchestrator;
use Pulsar\DataProtection\PurgeResult;

use function array_map;
use function array_sum;
use function count;
use function sprintf;

/**
 * Applies the retention policies in `config/data_protection.php`.
 *
 * The on-demand half of retention execution; {@see \Pulsar\DataProtection\DataPurgeJob}
 * is the scheduled half. Before either existed the orchestrator was built at boot
 * and resolved by nothing, so every retention period in that file was a number an
 * operator could read and no process would ever apply.
 *
 * `--dry-run` counts without deleting, whatever `purge.dry_run` says, so the
 * effect of a schedule can be inspected before it runs. Without the flag the
 * command obeys the configured mode, which is what makes a console run and a
 * scheduled run the same operation rather than two policies.
 *
 * Usage: data:purge [--dry-run]
 */
#[Internal]
final class DataPurgeCommand extends Command
{
    public function __construct(
        private readonly DataPurgeOrchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'data:purge';
        $this->description = 'Apply the retention policies in config/data_protection.php, deleting expired records';
        $this->addOption(
            'dry-run',
            'Count expired records without deleting anything, regardless of purge.dry_run',
        );
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = $input->hasOption('dry-run');

        $results = $dryRun
            ? $this->orchestrator->dryRun()
            : $this->orchestrator->purgeAll();

        if ($results === []) {
            $output->warning(
                'No retention category has both a purge implementation and a policy, so nothing '
                . 'was examined. Check that the categories in config/data_protection.php match '
                . 'the purgers the container holds (audit_logs, user_sessions by default).',
            );

            return ExitCode::Success->value;
        }

        foreach ($results as $result) {
            $output->info(sprintf(
                '%-20s %6d record(s) %s in %.1f ms',
                $result->category,
                $result->purgedCount,
                $result->dryRun ? 'eligible' : 'purged',
                $result->durationMs,
            ));
        }

        $total = array_sum(array_map(static fn(PurgeResult $r): int => $r->purgedCount, $results));

        $output->success(sprintf(
            '%d record(s) %s across %d categor%s.',
            $total,
            $results[0]->dryRun ? 'eligible for purging' : 'purged',
            count($results),
            count($results) === 1 ? 'y' : 'ies',
        ));

        return ExitCode::Success->value;
    }
}
