<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use function count;

use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Resilience\Repair\RepairRunner;

use function sprintf;

/**
 * Run self-healing repair jobs.
 */
final class RepairCommand extends Command
{
    public function __construct(
        private readonly RepairRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'health:repair';
        $this->description = 'Run self-healing repair jobs';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $diagnoses = $this->runner->diagnoseAll();
        $needsRepair = 0;

        foreach ($diagnoses as $diagnosis) {
            $status = $diagnosis->needsRepair ? 'NEEDS REPAIR' : 'OK';
            $output->writeln(sprintf('  [%s] %s — %s', $status, $diagnosis->repairJobName, $diagnosis->description));

            if ($diagnosis->needsRepair) {
                $needsRepair++;
            }
        }

        $output->newLine();

        if ($needsRepair === 0) {
            $output->success('No repairs needed.');
            return ExitCode::Success->value;
        }

        $output->writeln(sprintf('Running %d repair(s)...', $needsRepair));
        $output->newLine();

        $results = $this->runner->repairAll();
        $failed = 0;

        foreach ($results as $result) {
            $status = $result->success ? 'FIXED' : 'FAILED';
            $output->writeln(sprintf('  [%s] %s — %s', $status, $result->repairJobName, $result->description));

            foreach ($result->actionsPerformed as $action) {
                $output->writeln(sprintf('    → %s', $action));
            }

            if (!$result->success) {
                $failed++;
            }
        }

        $output->newLine();

        if ($failed > 0) {
            $output->error(sprintf('%d/%d repair(s) failed.', $failed, count($results)));
            return ExitCode::Error->value;
        }

        $output->success(sprintf('All %d repair(s) completed successfully.', count($results)));
        return ExitCode::Success->value;
    }
}
