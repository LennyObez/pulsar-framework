<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\Output\TableFormatter;
use Pulsar\Console\OutputInterface;
use Pulsar\Deploy\CheckSeverity;
use Pulsar\Deploy\DeployCheckRunnerInterface;
use Pulsar\Deploy\DeployReport;
use Pulsar\Deploy\Exception\DeployException;

use function json_encode;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * CLI command that runs all deploy readiness checks.
 *
 * Usage: deploy:check [--env=production] [--json] [--strict]
 *
 * THE EXIT CODE IS THE GATE, and it did not used to be. An error-severity
 * result — debug mode on in production, no master key, a missing audit logger —
 * exited 0 unless the caller happened to add `--strict`, and printed "Use
 * --strict to enforce a non-zero exit code" while doing it. Both env templates
 * meanwhile told operators the gate "fails closed" on exactly those two checks.
 * Run on this repository with APP_DEBUG=true and PULSAR_MASTER_KEY empty, it
 * reported `FAIL debug-mode`, `FAIL master-key` and five more, then exited 0: a
 * pipeline reading the exit code deployed anyway. A gate whose default answer to
 * seven failures is success is not a gate, and the template's claim was simply
 * untrue.
 *
 * So: an error refuses the deploy, always. `--strict` now adds the only stricter
 * thing left — treating warnings as errors too — which is also what the same
 * flag means on `studio:console:guardian:deploy:check`, so the word has one
 * meaning across the tree instead of three.
 *
 * Severity is the operator's dial, in config/deploy.php: a check that should not
 * refuse a deploy is set to `warn` there, or `off`, in a diff someone can read.
 * That is the supported way to soften a gate — not a flag omitted at the call
 * site, where the softening leaves no trace at all.
 */
#[Internal]
final class DeployCheckCommand extends Command
{
    public function __construct(
        private readonly DeployCheckRunnerInterface $deployCheck,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'deploy:check';
        $this->description = 'Run deploy readiness checks for a target environment';
        $this->addOption('env', 'Target environment (local, staging, production)', 'e', 'production');
        $this->addOption('json', 'Output results as JSON', 'j');
        $this->addOption('strict', 'Also refuse the deploy on Warning-severity results (errors always refuse it)', 's');
    }

    /**
     * @throws DeployException
     * @throws JsonException If JSON encoding fails in JSON output mode
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $environment = $input->getStringOption('env', 'production');

        if ($environment === '') {
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
        DeployReport $report,
        bool $strict,
    ): int {
        $output->writeln(sprintf('Deploy Readiness Check: %s', $report->environment));
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

        if ($report->errors > 0) {
            $output->newLine();
            $output->error(sprintf(
                '%d error-severity check(s) failed. Deployment is refused.',
                $report->errors,
            ));
            return ExitCode::Error->value;
        }

        if ($strict && $report->warnings > 0) {
            $output->newLine();
            $output->error(sprintf(
                'Strict mode: %d warning(s) treated as errors. Deployment is refused.',
                $report->warnings,
            ));
            return ExitCode::Error->value;
        }

        if ($report->warnings > 0) {
            $output->newLine();
            $output->warning(sprintf(
                '%d warning(s) detected. Use --strict to refuse the deploy on warnings too.',
                $report->warnings,
            ));
        }

        return ExitCode::Success->value;
    }

    /**
     * Render results as JSON.
     *
     * @throws JsonException
     */
    private function renderJson(
        OutputInterface $output,
        DeployReport $report,
        bool $strict,
    ): int {
        $success = $report->errors === 0 && !($strict && $report->warnings > 0);

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

        return $success ? ExitCode::Success->value : ExitCode::Error->value;
    }
}
