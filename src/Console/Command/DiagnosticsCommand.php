<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use function count;
use function extension_loaded;
use function ini_get;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Core\Kernel;
use Pulsar\Core\Version;

use function sprintf;

/**
 * Displays system diagnostics and health checks.
 */
final class DiagnosticsCommand extends Command
{
    public function __construct(
        private readonly Kernel $kernel,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'diagnostics';
        $this->description = 'Display system diagnostics and health checks';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Pulsar Framework Diagnostics');
        $output->writeln(str_repeat('=', 40));
        $output->newLine();

        $this->showFrameworkInfo($output);
        $this->showPhpInfo($output);
        $this->showExtensionInfo($output);
        $this->showMemoryInfo($output);
        $this->showPathInfo($output);

        return ExitCode::Success->value;
    }

    private function showFrameworkInfo(OutputInterface $output): void
    {
        $output->info('Framework');
        $output->writeln(sprintf('  Version:      %s', Version::full()));
        $output->writeln(sprintf('  Kernel:       %s', $this->kernel->booted ? 'Booted' : 'Not booted'));

        $extensions = $this->kernel->extensionBootstrap()?->registry;
        if ($extensions !== null) {
            $output->writeln(sprintf('  Extensions:   %d loaded', $extensions->count()));
        } else {
            $output->writeln('  Extensions:   Not configured');
        }

        $output->writeln(sprintf('  Routes:       %d registered', $this->kernel->router()->count()));
        $output->newLine();
    }

    private function showPhpInfo(OutputInterface $output): void
    {
        $output->info('PHP Environment');
        $output->writeln(sprintf('  Version:      %s', PHP_VERSION));
        $output->writeln(sprintf('  SAPI:         %s', PHP_SAPI));
        $output->writeln(sprintf('  OS:           %s', PHP_OS_FAMILY));
        $output->writeln(sprintf('  Architecture: %s-bit', PHP_INT_SIZE * 8));
        $output->newLine();
    }

    private function showExtensionInfo(OutputInterface $output): void
    {
        $required = ['json', 'mbstring', 'pcre'];
        $optional = ['opcache', 'apcu', 'redis'];

        $output->info('PHP Extensions');

        $output->writeln('  Required:');
        foreach ($required as $ext) {
            $status = extension_loaded($ext) ? 'OK' : 'MISSING';
            $symbol = extension_loaded($ext) ? '+' : '-';
            $output->writeln(sprintf('    [%s] %-15s %s', $symbol, $ext, $status));
        }

        $output->writeln('  Optional:');
        foreach ($optional as $ext) {
            $status = extension_loaded($ext) ? 'Loaded' : 'Not loaded';
            $symbol = extension_loaded($ext) ? '+' : ' ';
            $output->writeln(sprintf('    [%s] %-15s %s', $symbol, $ext, $status));
        }
        $output->newLine();
    }

    private function showMemoryInfo(OutputInterface $output): void
    {
        $output->info('Memory');
        $output->writeln(sprintf('  Limit:        %s', ini_get('memory_limit') ?: 'Not set'));
        $output->writeln(sprintf('  Usage:        %s', $this->formatBytes(memory_get_usage(true))));
        $output->writeln(sprintf('  Peak:         %s', $this->formatBytes(memory_get_peak_usage(true))));
        $output->newLine();
    }

    private function showPathInfo(OutputInterface $output): void
    {
        $output->info('Paths');
        $output->writeln(sprintf('  Working Dir:  %s', getcwd() ?: 'Unknown'));
        $output->writeln(sprintf('  Temp Dir:     %s', sys_get_temp_dir()));

        if (file_exists('composer.json')) {
            $output->writeln('  Composer:     Found');
        } else {
            $output->writeln('  Composer:     Not found in current directory');
        }
        $output->newLine();
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unit = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size = $size / 1024.0;
            $unit++;
        }

        return sprintf('%.2f %s', $size, $units[min($unit, 3)]);
    }
}
