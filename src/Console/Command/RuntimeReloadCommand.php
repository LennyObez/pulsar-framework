<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function file_exists;
use function file_get_contents;
use function function_exists;
use function sprintf;
use function trim;

use const PHP_OS_FAMILY;

/**
 * Send reload signal to the running persistent runtime.
 */
final class RuntimeReloadCommand extends Command
{
    #[Override]
    protected function configure(): void
    {
        $this->name = 'runtime:reload';
        $this->description = 'Send reload signal to the running persistent runtime';
        $this->addOption('pid', 'Process ID of the runtime worker');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output->error('Runtime reload via signal is not supported on Windows.');

            return ExitCode::Error->value;
        }

        if (!function_exists('posix_kill')) {
            $output->error('The posix extension is required for runtime:reload.');

            return ExitCode::Error->value;
        }

        $pidOption = $input->getNullableStringOption('pid');
        $pid = $pidOption !== null ? (int) $pidOption : $this->detectPid();

        if ($pid === null || $pid <= 0) {
            $output->error('Cannot detect runtime PID. Use --pid to specify it manually.');

            return ExitCode::Error->value;
        }

        $sigusr1 = SIGUSR1;
        $sent = posix_kill($pid, $sigusr1);

        if (!$sent) {
            $output->error(sprintf('Failed to send reload signal to PID %d', $pid));

            return ExitCode::Error->value;
        }

        $output->success(sprintf('Reload signal sent to PID %d', $pid));

        return ExitCode::Success->value;
    }

    private function detectPid(): ?int
    {
        $pidFile = var_path('run/runtime.pid');

        if (file_exists($pidFile)) {
            $content = file_get_contents($pidFile);

            if ($content !== false) {
                return (int) trim($content);
            }
        }

        return null;
    }
}
