<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use function is_string;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\Output\TableFormatter;
use Pulsar\Console\OutputInterface;
use Pulsar\Deploy\CheckSeverity;
use Pulsar\Deploy\DeployCheck;

use function sprintf;

/**
 * CLI command that runs all deploy readiness checks.
 *
 * Usage: deploy:check [--env=production] [--json] [--strict]
 */
#[Internal]
final class DeployCheckCommand extends Command
{
    public function __construct(
        private readonly DeployCheck $deployCheck,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'deploy:check';
        $this->description = 'Run deploy readiness checks for a target environment';
        $this->addOption('env', 'Target environment (local, staging, production)', 'e', 'production');
        $this->addOption('json', 'Output results as JSON', 'j');
        $this->addOption('strict', 'Exit with error code if any Error-severity results', 's');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $environment = $input->getOption('env', 'production');

        if (!is_string($environment) || $environment === '') {
            $environment = 'production';
        }

        $jsonMode = $input->hasOption('json');
        $strict = $input->hasOption('strict');

        $report = $this->deployCheck->run($environment);

        if ($jsonMode) {
            return $this->renderJson($output, $report, $strict);
        }

        return $this->renderTable($output, $report, $strict);
    }

    /**
     * Render results as a formatted table.
     */
    private function renderTable(
        OutputInterface $output,
        \Pulsar\Deploy\DeployReport $report,
        bool $strict,
    ): int {
        $output->writeln(sprintf('Deploy Readiness Check — %s', $report->environment));
        $output->writeln(str_repeat('=', 50));
        $output->newLine();

        if ($report->results === []) {
            $output->writeln('No deploy checks registered.');
            return ExitCode::Success->value;
        }

        $table = new TableFormatter();
        $table->setHeaders(['Status', 'Check', 'Message']);

        foreach ($report->results as $result) {
            $icon = match ($result->severity) {
                CheckSeverity::Pass => 'PASS',
                CheckSeverity::Warning => 'WARN',
                CheckSeverity::Error => 'FAIL',
            };

            $table->addRow([$icon, $result->name, $result->message]);
        }

        $table->render($output);
        $output->newLine();

        // Show recommendations for non-passing checks
        $hasRecommendations = false;

        foreach ($report->results as $result) {
            if ($result->severity !== CheckSeverity::Pass && $result->recommendations !== []) {
                if (!$hasRecommendations) {
                    $output->writeln('Recommendations:');
                    $output->newLine();
                    $hasRecommendations = true;
                }

                $severityLabel = $result->severity === CheckSeverity::Error ? 'ERROR' : 'WARN';
                $output->writeln(sprintf('  [%s] %s:', $severityLabel, $result->name));

                foreach ($result->recommendations as $recommendation) {
                    $output->writeln(sprintf('    - %s', $recommendation));
                }

                $output->newLine();
            }
        }

        // Summary
        $output->writeln(sprintf(
            'Summary: %d passed, %d warnings, %d errors (%d total)',
            $report->passed,
            $report->warnings,
            $report->errors,
            $report->total(),
        ));

        if ($report->allPassed()) {
            $output->newLine();
            $output->success('All deploy checks passed.');
            return ExitCode::Success->value;
        }

        if ($strict && $report->errors > 0) {
            $output->newLine();
            $output->error(sprintf(
                'Strict mode: %d error(s) detected. Deployment not recommended.',
                $report->errors,
            ));
            return ExitCode::Error->value;
        }

        if ($report->errors > 0) {
            $output->newLine();
            $output->warning(sprintf(
                '%d error(s) detected. Use --strict to enforce a non-zero exit code.',
                $report->errors,
            ));
        }

        return ExitCode::Success->value;
    }

    /**
     * Render results as JSON.
     */
    private function renderJson(
        OutputInterface $output,
        \Pulsar\Deploy\DeployReport $report,
        bool $strict,
    ): int {
        $hasStrictErrors = $strict && $report->errors > 0;
        $success = !$hasStrictErrors;

        /** @var list<array{name: string, severity: string, message: string, recommendations: list<string>}> $results */
        $results = [];

        foreach ($report->results as $result) {
            $results[] = [
                'name' => $result->name,
                'severity' => $result->severity->value,
                'message' => $result->message,
                'recommendations' => $result->recommendations,
            ];
        }

        $envelope = [
            'command' => 'deploy:check',
            'success' => $success,
            'data' => [
                'environment' => $report->environment,
                'summary' => [
                    'total' => $report->total(),
                    'passed' => $report->passed,
                    'warnings' => $report->warnings,
                    'errors' => $report->errors,
                ],
                'results' => $results,
            ],
        ];

        $json = json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $output->writeln($json);

        return $hasStrictErrors ? ExitCode::Error->value : ExitCode::Success->value;
    }
}
