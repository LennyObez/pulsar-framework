<?php

declare(strict_types=1);

namespace Pulsar\Studio\Command\Console\Guardian;

use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Studio\Command\Console\JsonOutputHelper;
use Pulsar\Supervisor\Supervisor;

use function sprintf;

/**
 * Runs all guardian checks: preflight and invariant checks from the Supervisor.
 */
#[Internal]
final class GuardianCheckCommand extends Command
{
    public function __construct(
        private readonly Supervisor $supervisor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'studio:console:guardian:check';
        $this->description = 'Run all guardian checks';
        $this->addOption('json', 'Output as JSON', 'j');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isJson = $input->hasOption('json');

        $preflightResults = $this->supervisor->runPreflightChecks();
        $invariantResults = $this->supervisor->runInvariantChecks();

        $preflightPassed = 0;
        $preflightFailed = 0;

        foreach ($preflightResults as $result) {
            $result->passed ? $preflightPassed++ : $preflightFailed++;
        }

        $invariantPassed = 0;
        $invariantFailed = 0;

        foreach ($invariantResults as $result) {
            $result->passed ? $invariantPassed++ : $invariantFailed++;
        }

        $allPassed = $preflightFailed === 0 && $invariantFailed === 0;

        $data = [
            'passed' => $allPassed,
            'preflight' => [
                'passed' => $preflightPassed,
                'failed' => $preflightFailed,
                'results' => array_map(static fn($r) => [
                    'passed' => $r->passed,
                    'message' => $r->message,
                    'findings' => $r->findings,
                ], $preflightResults),
            ],
            'invariant' => [
                'passed' => $invariantPassed,
                'failed' => $invariantFailed,
                'results' => array_map(static fn($r) => [
                    'passed' => $r->passed,
                    'message' => $r->message,
                    'findings' => $r->findings,
                ], $invariantResults),
            ],
        ];

        if ($isJson) {
            $output->writeln(JsonOutputHelper::encode('studio:console:guardian:check', $allPassed, $data));

            return $allPassed ? ExitCode::Success->value : ExitCode::Error->value;
        }

        $output->writeln('Guardian Checks');
        $output->writeln(str_repeat('=', 50));

        $output->writeln('');
        $output->writeln(sprintf('  Preflight:  %d passed, %d failed', $preflightPassed, $preflightFailed));

        foreach ($preflightResults as $result) {
            $icon = $result->passed ? '+' : '!';
            $output->writeln(sprintf('    [%s] %s', $icon, $result->message));
        }

        $output->writeln('');
        $output->writeln(sprintf('  Invariant:  %d passed, %d failed', $invariantPassed, $invariantFailed));

        foreach ($invariantResults as $result) {
            $icon = $result->passed ? '+' : '!';
            $output->writeln(sprintf('    [%s] %s', $icon, $result->message));
        }

        $output->writeln('');
        $output->writeln($allPassed ? '  All checks passed.' : '  Some checks failed.');

        return $allPassed ? ExitCode::Success->value : ExitCode::Error->value;
    }
}
