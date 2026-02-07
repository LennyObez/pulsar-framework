<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use const DIRECTORY_SEPARATOR;

use function fgets;
use function file_exists;
use function file_get_contents;
use function fwrite;

use Override;

use function preg_match;
use function preg_replace;

use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Support\AtomicFileWriter;

use function random_bytes;
use function sodium_bin2hex;

use SodiumException;

use function sprintf;

use const STDERR;
use const STDIN;

use function str_contains;
use function strtolower;
use function trim;

/**
 * Generates a cryptographically secure master key for FrameworkCache integrity.
 *
 * When --write is used:
 * - If `.env` does not exist, copies `.env.example` as template (if available)
 *   and fills in PULSAR_MASTER_KEY.
 * - If `.env` exists but PULSAR_MASTER_KEY is empty, fills it in.
 * - If `.env` exists and PULSAR_MASTER_KEY is already set, prompts for
 *   confirmation (use --force to skip the prompt).
 *
 * Usage: key:generate [--write] [--force]
 */
#[Internal]
final class KeyGenerateCommand extends Command
{
    /** Key length in bytes (matches SODIUM_CRYPTO_KDF_KEYBYTES). */
    private const int KEY_BYTES = 32;

    /** @var resource */
    private readonly mixed $stdin;

    /**
     * @param resource|null $stdin Readable stream for interactive prompts (default: STDIN)
     */
    public function __construct(
        private readonly string $basePath,
        mixed $stdin = null,
    ) {
        $this->stdin = $stdin ?? STDIN;
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'key:generate';
        $this->description = 'Generate a PULSAR_MASTER_KEY for cache integrity';
        $this->addOption('write', 'Write the key to .env (creates from .env.example if missing)', 'w');
        $this->addOption('force', 'Overwrite an existing PULSAR_MASTER_KEY without confirmation', 'f');
    }

    /**
     * @throws \Random\RandomException If random_bytes() fails
     * @throws SodiumException If sodium_bin2hex() fails
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $hex = sodium_bin2hex(random_bytes(self::KEY_BYTES));

        if (!$input->hasOption('write')) {
            $output->writeln(sprintf('PULSAR_MASTER_KEY=%s', $hex));

            return ExitCode::Success->value;
        }

        $envFile = $this->basePath . DIRECTORY_SEPARATOR . '.env';

        return $this->writeToEnv($envFile, $hex, $input->hasOption('force'), $output);
    }

    private function writeToEnv(string $envFile, string $hex, bool $force, OutputInterface $output): int
    {
        $line = sprintf('PULSAR_MASTER_KEY=%s', $hex);

        if (!file_exists($envFile)) {
            $content = $this->createFromTemplate($line);
            AtomicFileWriter::write($envFile, $content);
            $output->writeln(sprintf('Created %s with PULSAR_MASTER_KEY', $envFile));

            return ExitCode::Success->value;
        }

        $content = file_get_contents($envFile);

        if ($content === false) {
            $output->errorln(sprintf('Failed to read %s', $envFile));

            return ExitCode::Error->value;
        }

        // Key exists with a non-empty value — confirm before overwriting
        if (preg_match('/^PULSAR_MASTER_KEY=.+$/m', $content) === 1) {
            if (!$force && !$this->confirm($output)) {
                $output->writeln('Aborted.');

                return ExitCode::Success->value;
            }

            $content = preg_replace('/^PULSAR_MASTER_KEY=.*$/m', $line, $content);
            /** @var string $content */
            AtomicFileWriter::write($envFile, $content);
            $output->writeln(sprintf('PULSAR_MASTER_KEY replaced in %s', $envFile));

            return ExitCode::Success->value;
        }

        // Key line exists but is empty (PULSAR_MASTER_KEY=)
        if (str_contains($content, 'PULSAR_MASTER_KEY=')) {
            $content = preg_replace('/^PULSAR_MASTER_KEY=.*$/m', $line, $content);
            /** @var string $content */
            AtomicFileWriter::write($envFile, $content);
            $output->writeln(sprintf('PULSAR_MASTER_KEY set in %s', $envFile));

            return ExitCode::Success->value;
        }

        // Key not present at all — append
        $content = rtrim($content, "\n") . "\n" . $line . "\n";
        AtomicFileWriter::write($envFile, $content);
        $output->writeln(sprintf('PULSAR_MASTER_KEY appended to %s', $envFile));

        return ExitCode::Success->value;
    }

    /**
     * Create .env content from .env.example template, filling in the key.
     * Falls back to a single-line file if .env.example does not exist.
     */
    private function createFromTemplate(string $keyLine): string
    {
        $exampleFile = $this->basePath . DIRECTORY_SEPARATOR . '.env.example';

        if (!file_exists($exampleFile)) {
            return $keyLine . "\n";
        }

        $template = file_get_contents($exampleFile);

        if ($template === false) {
            return $keyLine . "\n";
        }

        // Replace the empty PULSAR_MASTER_KEY= line with the generated key
        if (str_contains($template, 'PULSAR_MASTER_KEY=')) {
            /** @var string */
            return preg_replace('/^PULSAR_MASTER_KEY=.*$/m', $keyLine, $template);
        }

        return rtrim($template, "\n") . "\n" . $keyLine . "\n";
    }

    /**
     * Prompt the user for confirmation before overwriting an existing key.
     */
    private function confirm(OutputInterface $output): bool
    {
        // Write prompt to stderr so it doesn't pollute stdout
        fwrite(STDERR, 'PULSAR_MASTER_KEY is already set. Replace it? [y/N] ');

        $answer = fgets($this->stdin);

        if ($answer === false) {
            return false;
        }

        return strtolower(trim($answer)) === 'y';
    }
}
