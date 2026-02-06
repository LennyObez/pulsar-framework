<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Supervisor\PreflightCheck\PreflightCheckInterface;
use Pulsar\Supervisor\PreflightCheck\PreflightRunner;

use function sprintf;

/**
 * Run all registered supervisor preflight checks and report results.
 */
final class SupervisorCheckCommand extends Command
{
    private readonly PreflightRunner $runner;

    /**
     * @param list<PreflightCheckInterface> $checks
     */
    public function __construct(array $checks)
    {
        $this->runner = new PreflightRunner($checks);
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'supervisor:check';
        $this->description = 'Run supervisor preflight checks';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Supervisor Preflight Checks');
        $output->writeln('==========================');
        $output->newLine();

        $results = $this->runner->run();
        $allPassed = true;

        foreach ($results as $result) {
            $icon = $result->passed ? 'OK' : 'FAIL';
            $output->writeln(sprintf('  [%s] %s', $icon, $result->message));

            foreach ($result->findings as $finding) {
                $output->writeln(sprintf('        %s', $finding));
            }

            if (!$result->passed) {
                $allPassed = false;
            }
        }

        $output->newLine();

        if ($results === []) {
            $output->info('No preflight checks registered.');
            return ExitCode::Success->value;
        }

        if ($allPassed) {
            $output->success('All preflight checks passed.');
            return ExitCode::Success->value;
        }

        $output->error('One or more preflight checks failed.');
        return ExitCode::Error->value;
    }
}
