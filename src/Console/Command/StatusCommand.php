<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Core\KernelInterface;
use Pulsar\Core\Version;

use function count;
use function php_sapi_name;
use function php_uname;
use function phpversion;
use function sprintf;

/**
 * Show framework status overview.
 *
 * Displays version, PHP info, boot status, extension count, and route count.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class StatusCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'status';
        $this->description = 'Show framework status overview';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Pulsar Framework Status');
        $output->writeln(str_repeat('=', 40));
        $output->newLine();

        // Framework info
        $output->writeln(sprintf('  Version:     %s', Version::full()));
        $output->writeln(sprintf('  PHP:         %s (%s)', phpversion() ?: PHP_VERSION, php_sapi_name() ?: 'unknown'));
        $output->writeln(sprintf('  Platform:    %s', php_uname('s') . ' ' . php_uname('r')));
        $output->writeln(sprintf('  Booted:      %s', $this->kernel->booted ? 'Yes' : 'No'));

        // Extensions
        $bootstrap = $this->kernel->extensionBootstrap();

        if ($bootstrap !== null) {
            $registry = $bootstrap->registry;
            $output->writeln(sprintf('  Extensions:  %d', $registry->count()));

            if ($registry->hasFailed()) {
                $failed = $registry->failed();
                $output->warning(sprintf('  Failed:      %d', count($failed)));
            }
        }

        // Routes
        if ($this->kernel->booted) {
            $routeCount = $this->kernel->router()->count();
            $output->writeln(sprintf('  Routes:      %d', $routeCount));
        }

        // Config
        $configManager = $this->kernel->configManager();

        if ($configManager !== null) {
            $configPath = $configManager->configPath();
            $output->writeln(sprintf('  Config:      %s', $configPath ?? 'not set'));
        }

        // Boot profile
        $profile = $this->kernel->bootProfile();

        if ($profile !== null) {
            $output->newLine();
            $output->writeln('Boot Profile:');
            $output->writeln(sprintf('  Duration:    %.2f ms', $profile->totalUs / 1000));
            $output->writeln(sprintf('  Cache Hit:   %s', $profile->cacheHit ? 'Yes' : 'No'));
            $output->writeln(sprintf('  Routes:      %s', $profile->routesCached ? 'Cached' : 'Computed'));
        }

        $output->newLine();
        $output->success('Status check complete.');

        return ExitCode::Success->value;
    }
}
