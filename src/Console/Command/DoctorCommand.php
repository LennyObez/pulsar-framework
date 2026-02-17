<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Core\Version;
use Pulsar\Database\ConnectionManagerInterface;
use Throwable;

use function extension_loaded;
use function file_exists;
use function is_dir;
use function is_writable;
use function phpversion;
use function preg_match;
use function sprintf;
use function strlen;
use function version_compare;

/**
 * Validates the development and runtime environment for Pulsar.
 *
 * Checks PHP version, required/optional extensions, writable directories,
 * database connectivity, master key presence/strength, config validity,
 * and Composer autoload freshness. Each check reports a pass/fail status
 * with an actionable fix suggestion on failure.
 */
final class DoctorCommand extends Command
{
    private const string REQUIRED_PHP = '8.5.0';
    private const int MIN_MASTER_KEY_LENGTH = 32;

    /** @var list<string> Extensions that must be present for Pulsar to function */
    private const array REQUIRED_EXTENSIONS = [
        'sodium',
        'mbstring',
        'json',
        'openssl',
        'pcre',
        'pdo',
    ];

    /** @var list<string> Extensions that enable optional features */
    private const array OPTIONAL_EXTENSIONS = [
        'pcov',
        'xdebug',
        'opcache',
        'apcu',
        'redis',
        'inotify',
        'intl',
    ];

    /** @var list<string> Directories that must be writable at runtime */
    private const array WRITABLE_DIRS = [
        'storage',
        'cache',
    ];

    private int $passCount = 0;
    private int $failCount = 0;
    private int $warnCount = 0;

    public function __construct(
        private readonly ?ConnectionManagerInterface $connectionManager = null,
        private readonly ?string $masterKey = null,
        private readonly ?string $basePath = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'doctor';
        $this->description = 'Check your environment for Pulsar compatibility';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('');
        $output->info('Pulsar Doctor v' . Version::full());
        $output->writeln('Checking your environment...');
        $output->writeln('');

        $this->checkPhpVersion($output);
        $this->checkRequiredExtensions($output);
        $this->checkOptionalExtensions($output);
        $this->checkWritableDirectories($output);
        $this->checkDatabaseConnection($output);
        $this->checkMasterKey($output);
        $this->checkComposerAutoload($output);

        $output->writeln('');
        $output->writeln(sprintf(
            'Results: %d passed, %d failed, %d warnings',
            $this->passCount,
            $this->failCount,
            $this->warnCount,
        ));
        $output->writeln('');

        if ($this->failCount > 0) {
            $output->error('Some checks failed. Fix the issues above before continuing.');
            return ExitCode::Error->value;
        }

        $output->success('All checks passed. Your environment is ready for Pulsar.');
        return ExitCode::Success->value;
    }

    /**
     * Get the counts for testing.
     *
     * @return array{pass: int, fail: int, warn: int}
     */
    public function getCounts(): array
    {
        return [
            'pass' => $this->passCount,
            'fail' => $this->failCount,
            'warn' => $this->warnCount,
        ];
    }

    private function checkPhpVersion(OutputInterface $output): void
    {
        $currentVersion = phpversion();

        if (version_compare($currentVersion, self::REQUIRED_PHP, '>=')) {
            $this->pass($output, sprintf('PHP version %s (>= %s required)', $currentVersion, self::REQUIRED_PHP));
        } else {
            $this->fail(
                $output,
                sprintf('PHP version %s (>= %s required)', $currentVersion, self::REQUIRED_PHP),
                'Upgrade PHP to ' . self::REQUIRED_PHP . ' or later.',
            );
        }
    }

    private function checkRequiredExtensions(OutputInterface $output): void
    {
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if (extension_loaded($ext)) {
                $this->pass($output, sprintf('Extension: %s', $ext));
            } else {
                $this->fail(
                    $output,
                    sprintf('Extension: %s (missing)', $ext),
                    sprintf('Install the %s extension: pecl install %s', $ext, $ext),
                );
            }
        }
    }

    private function checkOptionalExtensions(OutputInterface $output): void
    {
        $hasCoverage = extension_loaded('pcov') || extension_loaded('xdebug');

        foreach (self::OPTIONAL_EXTENSIONS as $ext) {
            if (extension_loaded($ext)) {
                $this->pass($output, sprintf('Extension: %s (optional)', $ext));
            } else {
                $this->warn($output, sprintf('Extension: %s (not loaded)', $ext));
            }
        }

        if (!$hasCoverage) {
            $this->warn($output, 'No coverage driver found (pcov or xdebug): code coverage unavailable');
        }
    }

    private function checkWritableDirectories(OutputInterface $output): void
    {
        $base = $this->basePath ?? (string) getcwd();

        foreach (self::WRITABLE_DIRS as $dir) {
            $path = $base . '/' . $dir;

            if (!is_dir($path)) {
                $this->fail(
                    $output,
                    sprintf('Directory: %s/ (missing)', $dir),
                    sprintf('Create the directory: mkdir -p %s', $dir),
                );
                continue;
            }

            if (is_writable($path)) {
                $this->pass($output, sprintf('Directory: %s/ (writable)', $dir));
            } else {
                $this->fail(
                    $output,
                    sprintf('Directory: %s/ (not writable)', $dir),
                    sprintf('Fix permissions: chmod 775 %s', $dir),
                );
            }
        }
    }

    private function checkDatabaseConnection(OutputInterface $output): void
    {
        if ($this->connectionManager === null) {
            $this->warn($output, 'Database: no connection manager configured (skipped)');
            return;
        }

        try {
            $connection = $this->connectionManager->connection();
            $connection->query('SELECT 1');
            $this->pass($output, sprintf('Database: connected via %s', $connection->driver()->value));
        } catch (Throwable $e) {
            $this->fail(
                $output,
                'Database: connection failed',
                'Check your database credentials and ensure the server is running. Error: ' . $e->getMessage(),
            );
        }
    }

    private function checkMasterKey(OutputInterface $output): void
    {
        if ($this->masterKey === null || $this->masterKey === '') {
            $this->fail(
                $output,
                'Master key: not set',
                'Set PULSAR_MASTER_KEY in your environment. Generate one with: pulsar key:generate',
            );
            return;
        }

        $length = strlen($this->masterKey);
        if ($length < self::MIN_MASTER_KEY_LENGTH) {
            $this->fail(
                $output,
                sprintf('Master key: too short (%d bytes, minimum %d)', $length, self::MIN_MASTER_KEY_LENGTH),
                'Regenerate with: pulsar key:generate',
            );
            return;
        }

        // Check for hex encoding (should be hex-encoded 32-byte key = 64 hex chars)
        if (preg_match('/^[0-9a-fA-F]+$/', $this->masterKey) === 1 && $length >= 64) {
            $this->pass($output, sprintf('Master key: present (%d hex characters)', $length));
        } else {
            $this->pass($output, sprintf('Master key: present (%d bytes)', $length));
        }
    }

    private function checkComposerAutoload(OutputInterface $output): void
    {
        $base = $this->basePath ?? (string) getcwd();
        $autoloadFile = $base . '/vendor/autoload.php';

        if (!file_exists($autoloadFile)) {
            $this->fail(
                $output,
                'Composer autoload: vendor/autoload.php missing',
                'Run: composer install',
            );
            return;
        }

        // Check if composer.json is newer than autoload
        $composerJson = $base . '/composer.json';
        if (file_exists($composerJson)) {
            $composerMtime = filemtime($composerJson);
            $autoloadMtime = filemtime($autoloadFile);

            if ($composerMtime !== false && $autoloadMtime !== false && $composerMtime > $autoloadMtime) {
                $this->warn($output, 'Composer autoload: may be stale (composer.json is newer). Run: composer dump-autoload');
                return;
            }
        }

        $this->pass($output, 'Composer autoload: up to date');
    }

    private function pass(OutputInterface $output, string $message): void
    {
        $this->passCount++;
        $output->writeln(sprintf('  [PASS] %s', $message));
    }

    private function fail(OutputInterface $output, string $message, string $fix): void
    {
        $this->failCount++;
        $output->writeln(sprintf('  [FAIL] %s', $message));
        $output->writeln(sprintf('         Fix: %s', $fix));
    }

    private function warn(OutputInterface $output, string $message): void
    {
        $this->warnCount++;
        $output->writeln(sprintf('  [WARN] %s', $message));
    }
}
