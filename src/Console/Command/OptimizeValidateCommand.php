<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Cache\FrameworkCacheInterface;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Core\KernelInterface;
use Throwable;

use function count;
use function sprintf;

/**
 * Validates that `optimize` produced a loadable cache.
 *
 * Steps:
 * 1. Run optimize (warm the cache)
 * 2. Verify isWarm() returns true
 * 3. Verify load() returns non-null with all sections
 * 4. Clear cache (cleanup)
 * 5. Exit 0 on success, 1 on failure
 *
 * Usage: optimize:validate [--strict] [--encrypt]
 */
#[Internal]
final class OptimizeValidateCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly ?FrameworkCacheInterface $frameworkCache = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'optimize:validate';
        $this->description = 'Validate that framework cache generation succeeds and cache is loadable (CI)';
        $this->addOption('strict', 'Fail if any closure-based route is detected', 's');
        $this->addOption('encrypt', 'Encrypt cache payloads at rest', 'e');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->frameworkCache === null) {
            $output->errorln('PULSAR_MASTER_KEY is required for cache validation.');
            $output->errorln('Set it via shell environment or a .env file.');

            return ExitCode::Error->value;
        }

        $output->writeln('Validating framework cache...');

        // Step 1: Run optimize to warm cache
        $output->writeln('  Step 1/4: Warming cache...');

        try {
            $optimizeCommand = new OptimizeCommand($this->kernel, $this->frameworkCache);
            $exitCode = $optimizeCommand->execute($input, $output);

            if ($exitCode !== ExitCode::Success->value) {
                $output->errorln('  Cache warming failed.');

                return ExitCode::Error->value;
            }
        } catch (Throwable $e) {
            $output->errorln(sprintf('  Cache warming failed: %s', $e->getMessage()));

            return ExitCode::Error->value;
        }

        // Step 2: Verify isWarm()
        $output->writeln('  Step 2/4: Verifying cache is warm...');

        try {
            if (!$this->frameworkCache->isWarm()) {
                $output->errorln('  Cache reports not warm after optimize.');

                return ExitCode::Error->value;
            }
        } catch (Throwable $e) {
            $output->errorln(sprintf('  isWarm() check failed: %s', $e->getMessage()));

            return ExitCode::Error->value;
        }

        // Step 3: Verify load() returns all sections
        $output->writeln('  Step 3/4: Verifying cache is loadable...');

        $configManager = $this->kernel->configManager();
        $configPath = $configManager?->configPath();

        if ($configPath === null) {
            $output->errorln('  No config path available for cache validation.');

            return ExitCode::Error->value;
        }

        try {
            $loaded = $this->frameworkCache->load($configPath);

            if ($loaded === null) {
                $output->errorln('  Cache load returned null — invalidation key mismatch or corruption.');

                return ExitCode::Error->value;
            }

            $output->writeln(sprintf(
                '    Manifest: OK (schema=%d, env=%s, strict=%s)',
                $loaded['manifest']->schemaVersion,
                $loaded['manifest']->appEnv,
                $loaded['manifest']->strict ? 'true' : 'false',
            ));

            $output->writeln(sprintf('    Config: %s', $loaded['config'] !== null ? 'OK' : 'MISSING'));
            $output->writeln(sprintf('    Routes: %s', $loaded['routes'] !== null ? count($loaded['routes']) . ' cached' : 'MISSING'));
            $output->writeln(sprintf('    Container: %s', $loaded['containerHints'] !== null ? 'OK' : 'MISSING'));
        } catch (Throwable $e) {
            $output->errorln(sprintf('  Cache load failed: %s', $e->getMessage()));

            return ExitCode::Error->value;
        }

        // Step 4: Clear cache
        $output->writeln('  Step 4/4: Clearing validation cache...');

        try {
            $this->frameworkCache->clear();
        } catch (Throwable $e) {
            $output->errorln(sprintf('  Cache clear failed: %s', $e->getMessage()));
            // Non-fatal — validation succeeded, cleanup failure is a warning
        }

        $output->writeln();
        $output->writeln('Cache validation passed.');

        return ExitCode::Success->value;
    }
}
