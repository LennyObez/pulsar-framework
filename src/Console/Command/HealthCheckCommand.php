<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Resilience\HealthCheck\HealthCheckRunnerInterface;
use Pulsar\Resilience\HealthCheck\HealthStatus;

use function sprintf;

/**
 * Run all registered health checks.
 */
final class HealthCheckCommand extends Command
{
    public function __construct(
        private readonly HealthCheckRunnerInterface $runner,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'health:check';
        $this->description = 'Run all registered health checks';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->runner->runAll();

        foreach ($report->results as $result) {
            $icon = match ($result->status) {
                HealthStatus::Healthy => 'OK',
                HealthStatus::Degraded => 'WARN',
                HealthStatus::Unhealthy => 'FAIL',
            };

            $output->writeln(sprintf(
                '  [%s] %s: %s (%.1fms)',
                $icon,
                $result->name,
                $result->message,
                $result->responseTimeMs,
            ));
        }

        $output->newLine();

        if ($report->isHealthy()) {
            $output->success('All health checks passed.');
            return ExitCode::Success->value;
        }

        $output->error(sprintf('Overall status: %s', $report->overallStatus->value));
        return ExitCode::Error->value;
    }
}
