<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use JsonException;
use Override;
use Pulsar\Config\SupervisorConfig;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Supervisor\StuckJobPolicy;
use Pulsar\Supervisor\WorkerRecyclePolicy;

use function json_encode;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Display the current supervisor configuration and policy state.
 */
final class SupervisorStatusCommand extends Command
{
    public function __construct(
        private readonly SupervisorConfig $config,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'supervisor:status';
        $this->description = 'Show supervisor configuration and policy info';
        $this->addOption('json', 'Output as JSON');
    }

    /**
     * @throws JsonException
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $recyclePolicy = $this->config->enabled
            ? WorkerRecyclePolicy::fromConfig($this->config)
            : null;

        $stuckJobPolicy = $this->config->enabled
            ? StuckJobPolicy::fromConfig($this->config)
            : null;

        if ($input->hasOption('json')) {
            return $this->renderJson($output, $recyclePolicy, $stuckJobPolicy);
        }

        return $this->renderText($output, $recyclePolicy, $stuckJobPolicy);
    }

    /**
     * @throws JsonException
     */
    private function renderJson(
        OutputInterface $output,
        ?WorkerRecyclePolicy $recyclePolicy,
        ?StuckJobPolicy $stuckJobPolicy,
    ): int {
        $data = [
            'enabled' => $this->config->enabled,
            'recycle_policy' => $recyclePolicy !== null ? [
                'max_requests' => $recyclePolicy->maxRequests,
                'memory_threshold_mb' => $recyclePolicy->memoryThresholdMb,
                'time_limit_seconds' => $recyclePolicy->timeLimitSeconds,
            ] : null,
            'stuck_job_policy' => $stuckJobPolicy !== null ? [
                'timeout_seconds' => $stuckJobPolicy->timeoutSeconds,
                'check_interval_seconds' => $stuckJobPolicy->checkIntervalSeconds,
                'move_to_dead_letter' => $stuckJobPolicy->moveToDeadLetter,
            ] : null,
        ];

        $output->writeln(json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));

        return ExitCode::Success->value;
    }

    private function renderText(
        OutputInterface $output,
        ?WorkerRecyclePolicy $recyclePolicy,
        ?StuckJobPolicy $stuckJobPolicy,
    ): int {
        $output->writeln('Supervisor Status');
        $output->writeln('=================');
        $output->newLine();

        $status = $this->config->enabled ? 'enabled' : 'disabled';
        $output->writeln(sprintf('  Status: %s', $status));
        $output->newLine();

        if ($recyclePolicy !== null) {
            $output->writeln('  Worker Recycle Policy:');
            $output->writeln(sprintf('    Max requests:       %d', $recyclePolicy->maxRequests));
            $output->writeln(sprintf('    Memory threshold:   %d MB', $recyclePolicy->memoryThresholdMb));
            $output->writeln(sprintf('    Time limit:         %d seconds', $recyclePolicy->timeLimitSeconds));
            $output->newLine();
        }

        if ($stuckJobPolicy !== null) {
            $output->writeln('  Stuck Job Policy:');
            $output->writeln(sprintf('    Timeout:            %d seconds', $stuckJobPolicy->timeoutSeconds));
            $output->writeln(sprintf('    Check interval:     %d seconds', $stuckJobPolicy->checkIntervalSeconds));
            $output->writeln(sprintf('    Dead-letter on stuck: %s', $stuckJobPolicy->moveToDeadLetter ? 'yes' : 'no'));
            $output->newLine();
        }

        if (!$this->config->enabled) {
            $output->warning('Supervisor is disabled. Enable it in config/supervisor.php or set SUPERVISOR_ENABLED=true.');
        }

        return ExitCode::Success->value;
    }
}
