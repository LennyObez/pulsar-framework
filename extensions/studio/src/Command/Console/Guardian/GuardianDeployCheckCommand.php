<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Command\Console\Guardian;

use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Deploy\CheckSeverity;
use Pulsar\Deploy\DeployCheckRunnerInterface;
use Pulsar\Extension\Studio\Command\Console\JsonOutputHelper;

use function is_string;
use function sprintf;

/**
 * Delegates to the DeployCheck orchestrator and displays results.
 */
#[Internal]
final class GuardianDeployCheckCommand extends Command
{
    public function __construct(
        private readonly DeployCheckRunnerInterface $deployCheck,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'studio:console:guardian:deploy:check';
        $this->description = 'Run deploy readiness checks';
        $this->addOption('env', 'Target environment', 'e', 'production');
        $this->addOption('strict', 'Fail on warnings', 's');
        $this->addOption('json', 'Output as JSON', 'j');
    }

    /**
     * @throws JsonException
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isJson = $input->hasOption('json');
        $isStrict = $input->hasOption('strict');
        $rawEnv = $input->getOption('env') ?? 'production';
        $environment = is_string($rawEnv) ? $rawEnv : 'production';

        $report = $this->deployCheck->run($environment);

        $success = $isStrict ? $report->allPassed() : $report->hasNoErrors();

        if ($isJson) {
            $data = [
                'environment' => $report->environment,
                'passed' => $report->passed,
                'warnings' => $report->warnings,
                'errors' => $report->errors,
                'total' => $report->total(),
                'results' => array_map(static fn($r) => [
                    'name' => $r->name,
                    'severity' => $r->severity->value,
                    'message' => $r->message,
                    'recommendations' => $r->recommendations,
                ], $report->results),
            ];

            $output->writeln(JsonOutputHelper::encode('studio:console:guardian:deploy:check', $success, $data));

            return $success ? ExitCode::Success->value : ExitCode::Error->value;
        }

        $output->writeln(sprintf('Deploy Readiness — %s', $environment));
        $output->writeln(str_repeat('=', 60));

        foreach ($report->results as $result) {
            $icon = match ($result->severity) {
                CheckSeverity::Pass => '+',
                CheckSeverity::Warning => '?',
                CheckSeverity::Error => '!',
            };
            $output->writeln(sprintf('  [%s] %s: %s', $icon, $result->name, $result->message));

            foreach ($result->recommendations as $rec) {
                $output->writeln(sprintf('      -> %s', $rec));
            }
        }

        $output->writeln();
        $output->writeln(sprintf('  Passed: %d  Warnings: %d  Errors: %d', $report->passed, $report->warnings, $report->errors));

        return $success ? ExitCode::Success->value : ExitCode::Error->value;
    }
}
