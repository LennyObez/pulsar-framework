<?php

declare(strict_types=1);

namespace Pulsar\Studio\Command;

use function dirname;
use function extension_loaded;
use function file_exists;
use function is_dir;
use function is_writable;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use Pulsar\Api\Internal;
use Pulsar\Config\StudioConfig;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function sprintf;

/**
 * Runs diagnostic checks for Studio.
 */
#[Internal]
final class StudioDoctorCommand extends Command
{
    public function __construct(
        private readonly StudioConfig $config,
        private readonly string $basePath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'studio:doctor';
        $this->description = 'Run Studio diagnostic checks';
        $this->addOption('json', 'Output as JSON', 'j');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isJson = $input->hasOption('json');
        $checks = [];
        $allPassed = true;

        // Check 1: Studio enabled
        $checks[] = [
            'name' => 'Studio enabled',
            'passed' => $this->config->enabled,
            'message' => $this->config->enabled ? 'Studio is enabled' : 'Studio is disabled in configuration',
        ];

        // Check 2: SQLite extension
        $sqliteLoaded = extension_loaded('pdo_sqlite');
        $checks[] = [
            'name' => 'PDO SQLite extension',
            'passed' => $sqliteLoaded,
            'message' => $sqliteLoaded ? 'pdo_sqlite is loaded' : 'pdo_sqlite extension is not loaded',
        ];
        if (!$sqliteLoaded) {
            $allPassed = false;
        }

        // Check 3: Storage directory writable
        $storagePath = $this->basePath . '/' . $this->config->storagePath;
        $storageDir = dirname($storagePath);
        $storageWritable = is_dir($storageDir) && is_writable($storageDir);
        $checks[] = [
            'name' => 'Storage directory writable',
            'passed' => $storageWritable,
            'message' => $storageWritable
                ? sprintf('Storage directory is writable: %s', $storageDir)
                : sprintf('Storage directory is not writable or does not exist: %s', $storageDir),
        ];
        if (!$storageWritable) {
            $allPassed = false;
        }

        // Check 4: Config file exists
        $configExists = file_exists($this->basePath . '/config/studio.php');
        $checks[] = [
            'name' => 'Config file exists',
            'passed' => $configExists,
            'message' => $configExists
                ? 'config/studio.php found'
                : 'config/studio.php not found',
        ];
        if (!$configExists) {
            $allPassed = false;
        }

        // Check 5: Port availability (basic check)
        $host = $this->config->server->host;
        $port = $this->config->server->port;
        $portAvailable = !$this->isPortInUse($host, $port);
        $checks[] = [
            'name' => 'Server port available',
            'passed' => $portAvailable,
            'message' => $portAvailable
                ? sprintf('Port %d is available on %s', $port, $host)
                : sprintf('Port %d is already in use on %s', $port, $host),
        ];

        // Check 6: Sodium extension (for encryption)
        $sodiumLoaded = extension_loaded('sodium');
        $checks[] = [
            'name' => 'Sodium extension (optional)',
            'passed' => $sodiumLoaded,
            'message' => $sodiumLoaded
                ? 'sodium is loaded (encryption-at-rest available)'
                : 'sodium not loaded (encryption-at-rest unavailable)',
        ];

        if ($isJson) {
            $result = [
                'all_passed' => $allPassed,
                'checks' => $checks,
            ];
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $allPassed ? ExitCode::Success->value : ExitCode::Error->value;
        }

        $output->writeln('Studio Doctor');
        $output->writeln(str_repeat('=', 30));
        $output->newLine();

        foreach ($checks as $check) {
            $symbol = $check['passed'] ? '+' : '-';
            $output->writeln(sprintf('  [%s] %s', $symbol, $check['name']));
            $output->writeln(sprintf('      %s', $check['message']));
        }

        $output->newLine();

        if ($allPassed) {
            $output->success('All checks passed.');
        } else {
            $output->warning('Some checks failed. Review the issues above.');
        }

        return $allPassed ? ExitCode::Success->value : ExitCode::Error->value;
    }

    private function isPortInUse(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);

        if ($connection !== false) {
            fclose($connection);
            return true;
        }

        return false;
    }
}
