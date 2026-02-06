<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use const DIRECTORY_SEPARATOR;

use function file_exists;
use function file_get_contents;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use JsonException;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Integrity\Exception\IntegrityException;
use Pulsar\Integrity\FileVerificationStatus;
use Pulsar\Integrity\ManifestFormat;
use Pulsar\Integrity\ManifestVerifier;

use function sprintf;

/**
 * Verify filesystem integrity against a stored manifest.
 *
 * Usage: integrity:verify [--strict] [--json]
 */
final class IntegrityVerifyCommand extends Command
{
    public function __construct(
        private readonly IntegrityConfig $config,
        private readonly string $basePath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'integrity:verify';
        $this->description = 'Verify filesystem integrity against a stored manifest';
        $this->addOption('strict', 'Exit with error code if any modifications detected', 's');
        $this->addOption('json', 'Output results as JSON envelope', 'j');
    }

    /**
     * @throws JsonException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $strict = $input->hasOption('strict');
        $jsonOutput = $input->hasOption('json');

        $manifestPath = $this->basePath . DIRECTORY_SEPARATOR . $this->config->manifestPath;

        if (!file_exists($manifestPath)) {
            if ($jsonOutput) {
                $this->writeJsonEnvelope($output, false, [
                    'error' => 'Manifest not found at ' . $manifestPath,
                ]);
            } else {
                $output->error(sprintf('Manifest not found at "%s"', $manifestPath));
                $output->writeln('  Run "integrity:build" first to generate a manifest.');
            }

            return ExitCode::Error->value;
        }

        $json = file_get_contents($manifestPath);

        if ($json === false) {
            if ($jsonOutput) {
                $this->writeJsonEnvelope($output, false, [
                    'error' => 'Failed to read manifest at ' . $manifestPath,
                ]);
            } else {
                $output->error(sprintf('Failed to read manifest at "%s"', $manifestPath));
            }

            return ExitCode::Error->value;
        }

        try {
            $manifest = ManifestFormat::fromJson($json);
        } catch (IntegrityException $e) {
            if ($jsonOutput) {
                $this->writeJsonEnvelope($output, false, [
                    'error' => $e->getMessage(),
                ]);
            } else {
                $output->error(sprintf('Manifest is corrupted: %s', $e->getMessage()));
            }

            return ExitCode::Error->value;
        }

        $verifier = new ManifestVerifier($this->basePath);
        $result = $verifier->verify($manifest);

        if ($jsonOutput) {
            $files = [];
            foreach ($result->files as $file) {
                $entry = [
                    'path' => $file->path,
                    'status' => $file->status->value,
                ];

                if ($file->expectedHash !== null) {
                    $entry['expected_hash'] = $file->expectedHash;
                }

                if ($file->actualHash !== null) {
                    $entry['actual_hash'] = $file->actualHash;
                }

                $files[] = $entry;
            }

            $this->writeJsonEnvelope($output, $result->passed, [
                'passed' => $result->passed,
                'verified' => $result->verified,
                'modified' => $result->modified,
                'missing' => $result->missing,
                'added' => $result->added,
                'files' => $files,
            ]);

            if ($strict && !$result->passed) {
                return ExitCode::Error->value;
            }

            return ExitCode::Success->value;
        }

        $output->writeln('Integrity verification results:');
        $output->newLine();

        foreach ($result->files as $file) {
            $icon = match ($file->status) {
                FileVerificationStatus::Verified => 'OK',
                FileVerificationStatus::Modified => 'MODIFIED',
                FileVerificationStatus::Missing => 'MISSING',
                FileVerificationStatus::Added => 'ADDED',
            };

            $output->writeln(sprintf('  [%s] %s', $icon, $file->path));
        }

        $output->newLine();
        $output->writeln(sprintf(
            '  Summary: %d verified, %d modified, %d missing, %d added',
            $result->verified,
            $result->modified,
            $result->missing,
            $result->added,
        ));
        $output->newLine();

        if ($result->passed) {
            $output->success('Integrity verification passed.');

            return ExitCode::Success->value;
        }

        if ($strict) {
            $output->error('Integrity verification failed (strict mode).');

            return ExitCode::Error->value;
        }

        $output->warning('Integrity verification detected changes.');

        return ExitCode::Success->value;
    }

    /**
     * Write a JSON envelope to output.
     *
     * @param array<string, mixed> $data
     *
     * @throws JsonException
     */
    private function writeJsonEnvelope(OutputInterface $output, bool $success, array $data): void
    {
        $envelope = [
            'command' => 'integrity:verify',
            'success' => $success,
            'data' => $data,
        ];

        $json = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $output->writeln($json);
    }
}
