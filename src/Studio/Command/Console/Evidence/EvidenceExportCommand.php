<?php

declare(strict_types=1);

namespace Pulsar\Studio\Command\Console\Evidence;

use function dirname;
use function file_put_contents;
use function is_dir;
use function is_int;
use function is_string;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Studio\Console\Evidence\EvidenceExporter;
use Pulsar\Studio\Exception\StudioException;

use function sprintf;
use function strlen;
use function time;

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

    protected function configure(): void
    {
        $this->name = 'studio:console:evidence:export';
        $this->description = 'Export Studio events as evidence archive';
        $this->addOption('output', 'Output file path', 'o');
        $this->addOption('json', 'Output metadata as JSON', 'j');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isJson = $input->hasOption('json');

        $rawOutputOption = $input->hasOption('output') ? $input->getOption('output') : null;
        $outputPath = is_string($rawOutputOption)
            ? $rawOutputOption
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
        $eventCount = is_int($rawEventCount) ? $rawEventCount : (int) (is_numeric($rawEventCount) ? $rawEventCount : 0);
        $rawChainLinkCount = $archive->manifest['chain_link_count'] ?? 0;
        $chainLinkCount = is_int($rawChainLinkCount) ? $rawChainLinkCount : (int) (is_numeric($rawChainLinkCount) ? $rawChainLinkCount : 0);

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
