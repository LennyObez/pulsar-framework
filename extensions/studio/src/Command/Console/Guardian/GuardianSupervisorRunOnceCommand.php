<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Command\Console\Guardian;

use JsonException;

use function memory_get_usage;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Studio\Command\Console\JsonOutputHelper;
use Pulsar\Supervisor\SupervisorInterface;

use function sprintf;
use function time;

/**
 * Runs a single supervisor evaluation cycle for diagnostics.
 *
 * Evaluates whether the current process would trigger a recycle
 * based on current memory usage and uptime, and runs all preflight checks.
 */
#[Internal]
final class GuardianSupervisorRunOnceCommand extends Command
{
    private readonly int $startTime;

    public function __construct(
        private readonly SupervisorInterface $supervisor,
    ) {
        $this->startTime = time();
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'studio:console:guardian:supervisor:run-once';
        $this->description = 'Run a single supervisor evaluation cycle';
        $this->addOption('requests', 'Simulated request count', 'r', '0');
        $this->addOption('json', 'Output as JSON', 'j');
    }

    /**
     * @throws JsonException
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isJson = $input->hasOption('json');
        /** @var string $rawRequests */
        $rawRequests = $input->getOption('requests') ?? '0';
        $requestCount = (int) $rawRequests;

        $memoryMb = (int) (memory_get_usage(true) / (1024 * 1024));
        $uptimeSeconds = time() - $this->startTime;

        $recycleRecord = $this->supervisor->shouldRecycle($requestCount, $memoryMb, $uptimeSeconds);
        $preflightResults = $this->supervisor->runPreflightChecks();

        $preflightPassed = 0;
        $preflightFailed = 0;

        foreach ($preflightResults as $result) {
            $result->passed ? $preflightPassed++ : $preflightFailed++;
        }

        $data = [
            'current_state' => [
                'memory_mb' => $memoryMb,
                'uptime_seconds' => $uptimeSeconds,
                'request_count' => $requestCount,
            ],
            'recycle' => $recycleRecord !== null ? [
                'triggered' => true,
                'reason' => $recycleRecord->reason->value,
                'action' => $recycleRecord->action->value,
            ] : [
                'triggered' => false,
            ],
            'preflight' => [
                'passed' => $preflightPassed,
                'failed' => $preflightFailed,
            ],
        ];

        if ($isJson) {
            $output->writeln(JsonOutputHelper::encode('studio:console:guardian:supervisor:run-once', true, $data));

            return ExitCode::Success->value;
        }

        $output->writeln('Supervisor Run-Once');
        $output->writeln(str_repeat('=', 50));
        $output->writeln(sprintf('  Memory:    %d MB', $memoryMb));
        $output->writeln(sprintf('  Uptime:    %d s', $uptimeSeconds));
        $output->writeln(sprintf('  Requests:  %d', $requestCount));
        $output->writeln();

        if ($recycleRecord !== null) {
            $output->writeln(sprintf('  Recycle:   TRIGGERED (%s -> %s)', $recycleRecord->reason->value, $recycleRecord->action->value));
        } else {
            $output->writeln('  Recycle:   not triggered');
        }

        $output->writeln();
        $output->writeln(sprintf('  Preflight: %d passed, %d failed', $preflightPassed, $preflightFailed));

        return ExitCode::Success->value;
    }
}
