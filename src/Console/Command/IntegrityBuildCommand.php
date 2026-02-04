<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use function count;

use const DIRECTORY_SEPARATOR;

use function dirname;
use function file_put_contents;
use function implode;
use function is_dir;

use JsonException;

use function mkdir;

use Override;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Integrity\Exception\IntegrityException;
use Pulsar\Integrity\ManifestBuilderInterface;
use Pulsar\Integrity\ManifestFormat;
use Pulsar\Integrity\ManifestSignerInterface;
use SodiumException;

use function sprintf;

/**
 * Build an integrity manifest from configured file paths.
 *
 * Usage: integrity:build [--sign] [--output=path]
 */
final class IntegrityBuildCommand extends Command
{
    public function __construct(
        private readonly IntegrityConfig $config,
        private readonly ManifestBuilderInterface $builder,
        private readonly string $basePath,
        private readonly ?ManifestSignerInterface $signer = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'integrity:build';
        $this->description = 'Build an integrity manifest from configured file paths';
        $this->addOption('sign', 'Sign the manifest with HMAC using PULSAR_MASTER_KEY', 's');
        $this->addOption('output', 'Custom output path for the manifest file', 'o');
    }

    /**
     * @throws JsonException If manifest canonicalization fails during signing
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $sign = $input->hasOption('sign');
        $outputPath = $input->getOption('output');

        if ($outputPath === null || $outputPath === true) {
            $outputPath = $this->basePath . DIRECTORY_SEPARATOR . $this->config->manifestPath;
        }

        /** @var string $outputPath */
        $output->writeln('Building integrity manifest...');
        $output->writeln(sprintf('  Include: %s', implode(', ', $this->config->include)));
        $output->writeln(sprintf('  Exclude: %s', implode(', ', $this->config->exclude)));
        $output->newLine();

        try {
            $manifest = $this->builder->build($this->config->include, $this->config->exclude);
        } catch (IntegrityException $e) {
            $output->error(sprintf('Build failed: %s', $e->getMessage()));

            return ExitCode::Error->value;
        }

        $output->writeln(sprintf('  Hashed %d file(s) using %s', count($manifest->entries), $manifest->algorithm));

        $signature = null;

        if ($sign) {
            if ($this->signer === null) {
                $output->error('Signing requested but no ManifestSignerInterface was provided (is PULSAR_MASTER_KEY set?)');

                return ExitCode::Error->value;
            }

            try {
                $signature = $this->signer->sign($manifest);
                $output->writeln('  Manifest signed with HMAC (BLAKE2b)');
            } catch (SodiumException $e) {
                $output->error(sprintf('Signing failed: %s', $e->getMessage()));

                return ExitCode::Error->value;
            }
        }

        try {
            $json = ManifestFormat::toJson($manifest, $signature);
        } catch (JsonException $e) {
            $output->error(sprintf('Serialization failed: %s', $e->getMessage()));

            return ExitCode::Error->value;
        }

        $dir = dirname($outputPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $written = file_put_contents($outputPath, $json);

        if ($written === false) {
            $output->error(sprintf('Failed to write manifest to "%s"', $outputPath));

            return ExitCode::Error->value;
        }

        $output->newLine();
        $output->success(sprintf('Manifest written to %s', $outputPath));

        return ExitCode::Success->value;
    }
}
