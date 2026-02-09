<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Command\Console\Guardian;

use function dirname;
use function file_put_contents;
use function is_dir;
use function is_string;

use JsonException;

use const LOCK_EX;

use function mkdir;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Integrity\Exception\IntegrityException;
use Pulsar\Integrity\ManifestBuilderInterface;
use Pulsar\Integrity\ManifestFormat;
use Pulsar\Integrity\ManifestSignerInterface;
use Pulsar\Extension\Studio\Command\Console\JsonOutputHelper;
use SodiumException;

use function sprintf;

/**
 * Builds an integrity manifest from the current filesystem state.
 */
#[Internal]
final class GuardianIntegrityBuildCommand extends Command
{
    public function __construct(
        private readonly ManifestBuilderInterface $builder,
        private readonly IntegrityConfig $config,
        private readonly ?ManifestSignerInterface $signer = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'studio:console:guardian:integrity:build';
        $this->description = 'Build an integrity manifest';
        $this->addOption('sign', 'Sign the manifest with HMAC', 's');
        $this->addOption('output', 'Output path', 'o');
        $this->addOption('json', 'Output as JSON', 'j');
    }

    /**
     * @throws JsonException
     * @throws IntegrityException If the manifest build process fails
     * @throws SodiumException If manifest signing fails
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isJson = $input->hasOption('json');
        $shouldSign = $input->hasOption('sign');
        $rawOutput = $input->getOption('output');
        $outputPath = is_string($rawOutput) ? $rawOutput : $this->config->manifestPath;

        $manifest = $this->builder->build($this->config->include, $this->config->exclude);

        $signature = null;

        if ($shouldSign && $this->signer !== null) {
            $signature = $this->signer->sign($manifest);
        } elseif ($shouldSign) {
            $message = 'Signing requested but no MasterKey available. Build manifest without signature or configure PULSAR_MASTER_KEY.';

            if ($isJson) {
                $output->writeln(JsonOutputHelper::encode('studio:console:guardian:integrity:build', false, ['error' => $message]));
            } else {
                $output->writeln($message);
            }

            return ExitCode::Error->value;
        }

        $json = ManifestFormat::toJson($manifest, $signature);

        $dir = dirname($outputPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }

        file_put_contents($outputPath, $json, LOCK_EX);

        $data = [
            'path' => $outputPath,
            'entries' => $manifest->entryCount,
            'signed' => $signature !== null,
        ];

        if ($isJson) {
            $output->writeln(JsonOutputHelper::encode('studio:console:guardian:integrity:build', true, $data));

            return ExitCode::Success->value;
        }

        $output->writeln('Integrity Manifest Built');
        $output->writeln(str_repeat('=', 50));
        $output->writeln(sprintf('  Path:     %s', $outputPath));
        $output->writeln(sprintf('  Entries:  %d', $manifest->entryCount));
        $output->writeln(sprintf('  Signed:   %s', $signature !== null ? 'yes' : 'no'));

        return ExitCode::Success->value;
    }
}
