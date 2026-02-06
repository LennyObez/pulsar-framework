<?php

declare(strict_types=1);

namespace Pulsar\Studio\Command\Console\Guardian;

use function file_exists;
use function file_get_contents;

use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Config\IntegrityPolicyMode;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Integrity\Exception\IntegrityException;
use Pulsar\Integrity\FileVerificationStatus;
use Pulsar\Integrity\ManifestFormat;
use Pulsar\Integrity\ManifestSigner;
use Pulsar\Integrity\ManifestVerifier;
use Pulsar\Studio\Command\Console\JsonOutputHelper;
use SodiumException;

use function sprintf;

/**
 * Verifies the integrity manifest against the current filesystem state.
 */
#[Internal]
final class GuardianIntegrityVerifyCommand extends Command
{
    public function __construct(
        private readonly ManifestVerifier $verifier,
        private readonly IntegrityConfig $config,
        private readonly ?ManifestSigner $signer = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'studio:console:guardian:integrity:verify';
        $this->description = 'Verify integrity manifest against filesystem';
        $this->addOption('strict', 'Fail on any discrepancy', 's');
        $this->addOption('json', 'Output as JSON', 'j');
    }

    /**
     * @throws JsonException
     * @throws IntegrityException If the manifest is malformed or missing required fields
     * @throws SodiumException If signature verification fails
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isJson = $input->hasOption('json');
        $isStrict = $input->hasOption('strict') || $this->config->mode === IntegrityPolicyMode::Strict;
        $manifestPath = $this->config->manifestPath;

        if (!file_exists($manifestPath)) {
            $message = sprintf('Manifest not found at %s. Run integrity:build first.', $manifestPath);

            if ($isJson) {
                $output->writeln(JsonOutputHelper::encode('studio:console:guardian:integrity:verify', false, ['error' => $message]));
            } else {
                $output->writeln($message);
            }

            return ExitCode::Error->value;
        }

        $json = file_get_contents($manifestPath);

        if ($json === false) {
            $message = sprintf('Failed to read manifest at %s.', $manifestPath);

            if ($isJson) {
                $output->writeln(JsonOutputHelper::encode('studio:console:guardian:integrity:verify', false, ['error' => $message]));
            } else {
                $output->writeln($message);
            }

            return ExitCode::Error->value;
        }

        $manifest = ManifestFormat::fromJson($json);

        // Verify signature if signer is available and manifest is signed
        if ($manifest->signature !== null && $this->signer !== null) {
            $signatureValid = $this->signer->verify($manifest);

            if (!$signatureValid) {
                $message = 'Manifest signature verification failed.';

                if ($isJson) {
                    $output->writeln(JsonOutputHelper::encode('studio:console:guardian:integrity:verify', false, [
                        'error' => $message,
                        'signature_valid' => false,
                    ]));
                } else {
                    $output->writeln($message);
                }

                return ExitCode::Error->value;
            }
        }

        $result = $this->verifier->verify($manifest);

        $files = [];

        foreach ($result->files as $file) {
            if ($file->status !== FileVerificationStatus::Verified) {
                $files[] = [
                    'path' => $file->path,
                    'status' => $file->status->value,
                    'expected_hash' => $file->expectedHash,
                    'actual_hash' => $file->actualHash,
                ];
            }
        }

        $data = [
            'passed' => $result->passed,
            'verified' => $result->verified,
            'modified' => $result->modified,
            'missing' => $result->missing,
            'added' => $result->added,
            'discrepancies' => $files,
        ];

        if ($isJson) {
            $output->writeln(JsonOutputHelper::encode('studio:console:guardian:integrity:verify', $result->passed, $data));

            return $result->passed ? ExitCode::Success->value : ($isStrict ? ExitCode::Error->value : ExitCode::Success->value);
        }

        $output->writeln('Integrity Verification');
        $output->writeln(str_repeat('=', 60));
        $output->writeln(sprintf('  Verified:  %d', $result->verified));
        $output->writeln(sprintf('  Modified:  %d', $result->modified));
        $output->writeln(sprintf('  Missing:   %d', $result->missing));
        $output->writeln(sprintf('  Added:     %d', $result->added));

        if ($files !== []) {
            $output->writeln();
            $output->writeln('  Discrepancies:');

            foreach ($files as $file) {
                $output->writeln(sprintf('    [%s] %s', $file['status'], $file['path']));
            }
        }

        $output->writeln();
        $output->writeln($result->passed ? '  Integrity check passed.' : '  Integrity check failed.');

        if (!$result->passed && $isStrict) {
            return ExitCode::Error->value;
        }

        return ExitCode::Success->value;
    }
}
