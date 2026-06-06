<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Command\Console\Guardian;

use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Config\SupervisorConfig;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Studio\Command\Console\JsonOutputHelper;

use function sprintf;

/**
 * Displays the current supervisor configuration and policy status.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class GuardianSupervisorStatusCommand extends Command
{
    public function __construct(
        private readonly SupervisorConfig $config,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'studio:console:guardian:supervisor:status';
        $this->description = 'Display supervisor configuration status';
        $this->addOption('json', 'Output as JSON', 'j');
    }

    /**
     * @throws JsonException
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isJson = $input->hasOption('json');

        $data = [
            'enabled' => $this->config->enabled,
            'recycle' => [
                'max_requests' => $this->config->recycleMaxRequests,
                'memory_threshold_mb' => $this->config->recycleMemoryThresholdMb,
                'time_limit_seconds' => $this->config->recycleTimeLimitSeconds,
            ],
            'stuck_job' => [
                'timeout_seconds' => $this->config->stuckJobTimeoutSeconds,
                'check_interval_seconds' => $this->config->stuckJobCheckIntervalSeconds,
                'move_to_dead_letter' => $this->config->stuckJobMoveToDeadLetter,
            ],
        ];

        if ($isJson) {
            $output->writeln(JsonOutputHelper::encode('studio:console:guardian:supervisor:status', true, $data));

            return ExitCode::Success->value;
        }

        $output->writeln('Supervisor Status');
        $output->writeln(str_repeat('=', 50));
        $output->writeln(sprintf('  Enabled: %s', $this->config->enabled ? 'yes' : 'no'));
        $output->writeln();
        $output->writeln('  Recycle Policy:');
        $output->writeln(sprintf('    Max requests:        %d', $this->config->recycleMaxRequests));
        $output->writeln(sprintf('    Memory threshold:    %d MB', $this->config->recycleMemoryThresholdMb));
        $output->writeln(sprintf('    Time limit:          %d s', $this->config->recycleTimeLimitSeconds));
        $output->writeln();
        $output->writeln('  Stuck Job Policy:');
        $output->writeln(sprintf('    Timeout:             %d s', $this->config->stuckJobTimeoutSeconds));
        $output->writeln(sprintf('    Check interval:      %d s', $this->config->stuckJobCheckIntervalSeconds));
        $output->writeln(sprintf('    Dead letter on fail: %s', $this->config->stuckJobMoveToDeadLetter ? 'yes' : 'no'));

        return ExitCode::Success->value;
    }
}
