<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Supervisor\PreflightCheck\PreflightRunnerInterface;

use function sprintf;

/**
 * Run all registered supervisor preflight checks and report results.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
final class SupervisorCheckCommand extends Command
{
    public function __construct(
        private readonly PreflightRunnerInterface $runner,
    ) {
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
