<?php

declare(strict_types=1);

namespace Pulsar\Studio\Command\Console;

use function dirname;
use function file_put_contents;
use function is_dir;
use function is_int;
use function is_string;

use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Studio\Console\Evidence\EvidenceExporter;
use Pulsar\Studio\Exception\StudioException;
use SodiumException;

use function sprintf;
use function strlen;
use function time;

/**
 * Exports Studio events as a verifiable evidence archive.
 */
#[Internal]
final class ConsoleExportCommand extends Command
{
    public function __construct(
        private readonly EvidenceExporter $exporter,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'studio:console:export';
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

        $rawOutputOption = $input->hasOption('output') ? $input->getOption('output') : null;
        $outputPath = is_string($rawOutputOption)
            ? $rawOutputOption
            : sprintf('studio-export-%d.json', time());

        try {
            $archive = $this->exporter->export();
        } catch (StudioException $e) {
            JsonOutputHelper::writeError($output, $isJson, 'encryption_key_required', $e->getMessage());
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
            $output->writeln(JsonOutputHelper::formatJson($metadata));
        } else {
            $output->success(sprintf('Archive exported to %s', $outputPath));
            JsonOutputHelper::writeField($output, 'Events', (string) $eventCount);
            JsonOutputHelper::writeField($output, 'Chain links', (string) $chainLinkCount);
            JsonOutputHelper::writeField($output, 'Size', sprintf('%d bytes', $metadata['size_bytes']));
            JsonOutputHelper::writeField($output, 'MAC', $metadata['mac_included'] ? 'Included' : 'None');
        }

        return ExitCode::Success->value;
    }
}
