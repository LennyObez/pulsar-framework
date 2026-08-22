<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Security\Posture\SecurityPostureReport;
use Pulsar\Security\Posture\SecurityPostureStatus;

use function count;
use function sprintf;

/**
 * Print the application security-posture report (OK / DEGRADED / FAIL).
 *
 * Exit code is non-zero when any control failed, so the command doubles as a
 * deployment gate (`pulsar security:check` in CI / a pre-start hook).
 */
final class SecurityCheckCommand extends Command
{
    public function __construct(
        private readonly SecurityPostureReport $report,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'security:check';
        $this->description = 'Report the application security posture (OK / DEGRADED / FAIL)';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->report->items as $item) {
            $icon = match ($item->status) {
                SecurityPostureStatus::Ok => 'OK  ',
                SecurityPostureStatus::Degraded => 'WARN',
                SecurityPostureStatus::Fail => 'FAIL',
            };

            $output->writeln(sprintf('  [%s] %s: %s', $icon, $item->name, $item->reason));

            if ($item->status !== SecurityPostureStatus::Ok && $item->fix !== '') {
                $output->writeln(sprintf('         fix: %s', $item->fix));
            }
        }

        $output->newLine();

        $failures = count($this->report->failures());
        $degraded = count($this->report->degraded());

        if ($this->report->hasFailures()) {
            $output->error(sprintf('Security posture: FAIL (%d failing, %d degraded)', $failures, $degraded));

            return ExitCode::Error->value;
        }

        if ($this->report->hasDegraded()) {
            $output->warning(sprintf('Security posture: DEGRADED (%d degraded)', $degraded));

            return ExitCode::Success->value;
        }

        $output->success('Security posture: OK');

        return ExitCode::Success->value;
    }
}
