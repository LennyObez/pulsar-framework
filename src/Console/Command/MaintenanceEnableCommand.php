<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Deploy\MaintenanceMode;

use function sprintf;

/**
 * Enable maintenance mode with optional bypass secret.
 *
 * Usage: maintenance:enable [--secret=abc123] [--message="Be right back"] [--retry=60] [--allow=127.0.0.1]
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class MaintenanceEnableCommand extends Command
{
    public function __construct(
        private readonly MaintenanceMode $maintenanceMode,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'maintenance:enable';
        $this->description = 'Enable maintenance mode with optional bypass secret';
        $this->addOption('secret', 'Bypass secret for allowed access', 's');
        $this->addOption('message', 'Custom maintenance message', 'm');
        $this->addOption('retry', 'Retry-After header value in seconds', 'r', '60');
        $this->addOption('allow', 'Comma-separated IP addresses to allow', 'a');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->maintenanceMode->isActive()) {
            $output->warning('Maintenance mode is already active.');
            return ExitCode::Success->value;
        }

        $secret = $input->getNullableStringOption('secret');
        $message = $input->getNullableStringOption('message');
        $retryAfter = $input->getIntOption('retry', 60);
        $allowRaw = $input->getStringOption('allow');

        $allowedIps = [];

        if ($allowRaw !== '') {
            $allowedIps = array_values(array_filter(
                array_map(trim(...), explode(',', $allowRaw)),
                static fn(string $ip): bool => $ip !== '',
            ));
        }

        $this->maintenanceMode->enable(
            secret: $secret,
            message: $message,
            retryAfter: $retryAfter,
            allowedIps: $allowedIps,
        );

        $output->success('Maintenance mode enabled.');

        if ($secret !== null) {
            $output->writeln(sprintf('  Bypass secret: %s', $secret));
        }

        if ($allowedIps !== []) {
            $output->writeln(sprintf('  Allowed IPs: %s', implode(', ', $allowedIps)));
        }

        return ExitCode::Success->value;
    }
}
