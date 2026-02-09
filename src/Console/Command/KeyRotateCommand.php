<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use const DIRECTORY_SEPARATOR;

use function file_exists;
use function file_get_contents;

use Override;

use function preg_match;
use function preg_replace;

use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Support\AtomicFileWriter;
use Random\RandomException;

use function random_bytes;

use RuntimeException;

use function sodium_bin2hex;

use SodiumException;

use function sprintf;
use function str_contains;

/**
 * Rotates the application master key.
 *
 * Generates a new master key and moves the current key to
 * PULSAR_MASTER_KEY_PREVIOUS for a rotation window. The previous key
 * enables fallback decryption and audit verification during transition.
 *
 * Usage: key:rotate [--write] [--clear-cache]
 */
#[Internal]
final class KeyRotateCommand extends Command
{
    private const int KEY_BYTES = 32;

    public function __construct(
        private readonly string $basePath,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'key:rotate';
        $this->description = 'Rotate the master key (new key + previous key for fallback)';
        $this->addOption('write', 'Write the rotated keys to .env', 'w');
        $this->addOption('clear-cache', 'Clear cached data after rotation', 'c');
    }

    /**
     * @throws RandomException
     * @throws RuntimeException
     * @throws SodiumException
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $newHex = sodium_bin2hex(random_bytes(self::KEY_BYTES));

        if (!$input->hasOption('write')) {
            $this->printInstructions($newHex, $output);

            return ExitCode::Success->value;
        }

        $envFile = $this->basePath . DIRECTORY_SEPARATOR . '.env';

        if (!file_exists($envFile)) {
            $output->errorln('.env file not found. Run key:generate --write first.');

            return ExitCode::Error->value;
        }

        $content = file_get_contents($envFile);

        if ($content === false) {
            $output->errorln(sprintf('Failed to read %s', $envFile));

            return ExitCode::Error->value;
        }

        // Extract current key to become previous
        if (preg_match('/^PULSAR_MASTER_KEY=([0-9a-fA-F]{64})$/m', $content, $matches) !== 1) {
            $output->errorln('PULSAR_MASTER_KEY not found or invalid in .env. Run key:generate --write first.');

            return ExitCode::Error->value;
        }

        $currentHex = $matches[1];

        // Set new key
        $content = preg_replace(
            '/^PULSAR_MASTER_KEY=[0-9a-fA-F]{64}$/m',
            sprintf('PULSAR_MASTER_KEY=%s', $newHex),
            $content,
        );

        /** @var string $content */

        // Set or update previous key
        if (str_contains($content, 'PULSAR_MASTER_KEY_PREVIOUS=')) {
            $content = preg_replace(
                '/^PULSAR_MASTER_KEY_PREVIOUS=.*$/m',
                sprintf('PULSAR_MASTER_KEY_PREVIOUS=%s', $currentHex),
                $content,
            );
        } else {
            /** @var non-falsy-string $content */
            $content = rtrim($content, "\n") . "\n" . sprintf('PULSAR_MASTER_KEY_PREVIOUS=%s', $currentHex) . "\n";
        }

        /** @var non-falsy-string $content */
        AtomicFileWriter::write($envFile, $content);

        $output->writeln('Key rotated successfully:');
        $output->writeln(sprintf('  PULSAR_MASTER_KEY          = %s...', substr($newHex, 0, 8)));
        $output->writeln(sprintf('  PULSAR_MASTER_KEY_PREVIOUS = %s...', substr($currentHex, 0, 8)));

        if ($input->hasOption('clear-cache')) {
            $output->writeln();
            $output->writeln('Run `php bin/pulsar optimize:clear` to clear cached data.');
        }

        $output->writeln();
        $output->writeln('Next steps:');
        $output->writeln('  1. Restart application workers to pick up new keys');
        $output->writeln('  2. Verify audit log integrity with the new key ring');
        $output->writeln('  3. After confirming all systems work, remove PULSAR_MASTER_KEY_PREVIOUS');

        return ExitCode::Success->value;
    }

    private function printInstructions(string $newHex, OutputInterface $output): void
    {
        $output->writeln('Generated new master key:');
        $output->writeln();
        $output->writeln(sprintf('  PULSAR_MASTER_KEY=%s', $newHex));
        $output->writeln();
        $output->writeln('To rotate, update your environment:');
        $output->writeln('  1. Set PULSAR_MASTER_KEY_PREVIOUS to your current PULSAR_MASTER_KEY value');
        $output->writeln('  2. Set PULSAR_MASTER_KEY to the new value above');
        $output->writeln('  3. Restart application workers');
        $output->writeln('  4. Clear caches: php bin/pulsar optimize:clear');
        $output->writeln('  5. Verify audit log integrity');
        $output->writeln('  6. After confirming, remove PULSAR_MASTER_KEY_PREVIOUS');
        $output->writeln();
        $output->writeln('Or use --write to update .env automatically:');
        $output->writeln('  php bin/pulsar key:rotate --write');
    }
}
