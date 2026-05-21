<?php

declare(strict_types=1);

namespace Pulsar\ImportExport\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\ImportExport\ExportRequest;
use Pulsar\ImportExport\ImportExportRegistry;

use function count;
use function explode;
use function file_put_contents;
use function json_encode;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * CLI command to export data from registered import/export providers.
 *
 * Usage:
 *   pulsar export:run [--providers=cms,forum,payments] [--format=json] [--output=export.json]
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class ExportCommand extends Command
{
    public function __construct(
        private readonly ImportExportRegistry $registry,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'export:run';
        $this->description = 'Export data from registered providers';
        $this->addOption('providers', 'Comma-separated provider names (default: all)', 'p');
        $this->addOption('format', 'Export format: json, csv, xml (default: json)', 'f');
        $this->addOption('output', 'Output file path (default: stdout)', 'o');
        $this->addOption('include-pii', 'Include PII fields in export');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $providers = $this->registry->getProviders();

        if ($providers === []) {
            $output->warning('No import/export providers registered.');

            return ExitCode::Success->value;
        }

        $providerNames = [];
        $providersOption = $input->getStringOption('providers', '');

        if ($providersOption !== '') {
            $providerNames = explode(',', $providersOption);
            $providerNames = array_map(trim(...), $providerNames);

            foreach ($providerNames as $name) {
                if (!$this->registry->has($name)) {
                    $output->errorln(sprintf('Provider "%s" is not registered.', $name));
                    $output->info('Available providers: ' . implode(', ', $this->registry->getProviderNames()));

                    return ExitCode::Error->value;
                }
            }
        }

        $formatOption = $input->getStringOption('format', 'json');
        $format = $formatOption !== '' ? $formatOption : 'json';
        $includePii = $input->hasOption('include-pii');

        $request = new ExportRequest(
            format: $format,
            includePii: $includePii,
        );

        $output->info('Starting export...');

        $results = $this->registry->exportAll($request, $providerNames);

        $combinedData = [];
        $allWarnings = [];

        foreach ($results as $result) {
            $combinedData[$result->providerName] = $result->data;
            $allWarnings = [...$allWarnings, ...$result->warnings];
        }

        $json = json_encode([
            'version' => '1.0',
            'providers' => $combinedData,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $outputFile = $input->getNullableStringOption('output');

        if ($outputFile !== null && $outputFile !== '') {
            file_put_contents($outputFile, $json);
            $output->success(sprintf('Export written to %s', $outputFile));
        } else {
            $output->writeln($json);
        }

        if ($allWarnings !== []) {
            $output->newLine();
            $output->warning(sprintf('%d warning(s):', count($allWarnings)));

            foreach ($allWarnings as $warning) {
                $output->writeln("  - {$warning}");
            }
        }

        $output->success(sprintf('Exported %d provider(s).', count($results)));

        return ExitCode::Success->value;
    }
}
