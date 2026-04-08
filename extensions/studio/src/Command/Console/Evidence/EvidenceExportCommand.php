<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Command\Console\Evidence;

use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceExporter;
use Pulsar\Extension\Studio\Exception\StudioException;
use SodiumException;

use function dirname;
use function file_put_contents;
use function is_dir;
use function is_numeric;
use function json_encode;
use function sprintf;
use function strlen;
use function time;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Exports Studio events as a verifiable evidence archive.
 *
 * Replaces the old `studio:console:export` command.
 */
#[Internal]
final class EvidenceExportCommand extends Command
{
    public function __construct(
        private readonly EvidenceExporter $exporter,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'studio:console:evidence:export';
        $this->description = 'Export Studio events as evidence archive';
        $this->addOption('output', 'Output file path', 'o');
        $this->addOption('json', 'Output metadata as JSON', 'j');
    }

    /**
     * @throws JsonException
     * @throws SodiumException If HMAC computation fails during export
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isJson = $input->hasOption('json');

        $outputPath = $input->hasOption('output')
            ? $input->getStringOption('output', sprintf('studio-export-%d.json', time()))
            : sprintf('studio-export-%d.json', time());

        try {
            $archive = $this->exporter->export();
        } catch (StudioException $e) {
            if ($isJson) {
                $error = [
                    'error' => 'encryption_key_required',
                    'message' => $e->getMessage(),
                ];
                $output->writeln(json_encode($error, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $output->errorln($e->getMessage());
            }

            return ExitCode::Error->value;
        }

        $archiveJson = $archive->toJson();

        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            $output->errorln(sprintf('Output directory does not exist: %s', $outputDir));

            return ExitCode::Error->value;
        }

        file_put_contents($outputPath, $archiveJson);

        $rawEventCount = $archive->manifest['event_count'] ?? 0;
        $eventCount = is_numeric($rawEventCount) ? (int) $rawEventCount : 0;
        $rawChainLinkCount = $archive->manifest['chain_link_count'] ?? 0;
        $chainLinkCount = is_numeric($rawChainLinkCount) ? (int) $rawChainLinkCount : 0;

        $metadata = [
            'file' => $outputPath,
            'event_count' => $eventCount,
            'chain_link_count' => $chainLinkCount,
            'size_bytes' => strlen($archiveJson),
            'mac_included' => $archive->mac !== null,
        ];

        if ($isJson) {
            $output->writeln(json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $output->success(sprintf('Archive exported to %s', $outputPath));
            $output->writeln(sprintf('  Events:      %d', $eventCount));
            $output->writeln(sprintf('  Chain links: %d', $chainLinkCount));
            $output->writeln(sprintf('  Size:        %d bytes', $metadata['size_bytes']));
            $output->writeln(sprintf('  MAC:         %s', $metadata['mac_included'] ? 'Included' : 'None'));
        }

        return ExitCode::Success->value;
    }
}
