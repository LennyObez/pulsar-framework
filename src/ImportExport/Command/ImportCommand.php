<?php

declare(strict_types=1);

namespace Pulsar\ImportExport\Command;

use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\ImportExport\DuplicateStrategy;
use Pulsar\ImportExport\ImportExportRegistry;
use Pulsar\ImportExport\ImportRequest;

use function array_keys;
use function count;
use function file_exists;
use function file_get_contents;
use function is_array;
use function is_string;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * CLI command to import data via registered import/export providers.
 *
 * Usage:
 *   pulsar import:run <file> [--provider=cms] [--dry-run] [--duplicates=skip|overwrite|fail]
 */
#[Internal]
final class ImportCommand extends Command
{
    public function __construct(
        private readonly ImportExportRegistry $registry,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'import:run';
        $this->description = 'Import data from a file via registered providers';
        $this->addArgument('file', 'Path to the import file', required: true);
        $this->addOption('provider', 'Target provider name (auto-detected if omitted)', 'p');
        $this->addOption('dry-run', 'Validate without persisting', 'd');
        $this->addOption('duplicates', 'Duplicate strategy: skip, overwrite, fail (default: skip)');
        $this->addOption('format', 'Import format: json, csv, xml (default: json)', 'f');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = $input->getArgument(0);

        if (!is_string($filePath) || $filePath === '') {
            $output->errorln('File path is required.');

            return ExitCode::Invalid->value;
        }

        if (!file_exists($filePath)) {
            $output->errorln(sprintf('File not found: %s', $filePath));

            return ExitCode::Error->value;
        }

        $content = file_get_contents($filePath);

        if ($content === false || $content === '') {
            $output->errorln('Failed to read file or file is empty.');

            return ExitCode::Error->value;
        }

        $formatOption = $input->getOption('format');
        $format = is_string($formatOption) && $formatOption !== '' ? $formatOption : 'json';

        $dryRun = $input->hasOption('dry-run');

        $duplicatesOption = $input->getOption('duplicates');
        $duplicateStrategy = DuplicateStrategy::Skip;

        if (is_string($duplicatesOption)) {
            $resolved = DuplicateStrategy::tryFrom($duplicatesOption);

            if ($resolved !== null) {
                $duplicateStrategy = $resolved;
            }
        }

        $providerOption = $input->getOption('provider');
        $providerNames = $this->resolveProviders($content, $providerOption, $format);

        if ($providerNames === []) {
            $output->errorln('Could not determine target provider. Use --provider=<name>.');
            $output->info('Available providers: ' . implode(', ', $this->registry->getProviderNames()));

            return ExitCode::Error->value;
        }

        $output->info(sprintf(
            '%s import from %s...',
            $dryRun ? 'Dry-run' : 'Running',
            $filePath,
        ));

        $hasErrors = false;

        foreach ($providerNames as $providerName) {
            if (!$this->registry->has($providerName)) {
                $output->errorln(sprintf('Provider "%s" is not registered.', $providerName));
                $hasErrors = true;

                continue;
            }

            $providerContent = $this->extractProviderContent($content, $providerName, $format);

            $request = new ImportRequest(
                content: $providerContent,
                format: $format,
                dryRun: $dryRun,
                duplicateStrategy: $duplicateStrategy,
            );

            $result = $this->registry->importTo($providerName, $request);

            $output->writeln(sprintf('  Provider: %s', $providerName));
            $output->writeln(sprintf('    Created: %d', $result->totalCreated()));
            $output->writeln(sprintf('    Updated: %d', $result->totalUpdated()));
            $output->writeln(sprintf('    Skipped: %d', $result->totalSkipped()));

            if ($result->warnings !== []) {
                $output->warning(sprintf('    %d warning(s):', count($result->warnings)));

                foreach ($result->warnings as $warning) {
                    $output->writeln("      - {$warning}");
                }
            }

            if ($result->hasErrors()) {
                $hasErrors = true;
                $output->errorln(sprintf('    %d error(s):', count($result->errors)));

                foreach ($result->errors as $error) {
                    $output->writeln("      - {$error}");
                }
            }
        }

        if ($dryRun) {
            $output->info('Dry-run complete. No data was persisted.');
        } elseif (!$hasErrors) {
            $output->success('Import completed successfully.');
        }

        return $hasErrors ? ExitCode::Error->value : ExitCode::Success->value;
    }

    /**
     * Resolve which providers to route the import to.
     *
     * @return list<string>
     */
    private function resolveProviders(string $content, mixed $providerOption, string $format): array
    {
        if (is_string($providerOption) && $providerOption !== '') {
            return [$providerOption];
        }

        // Auto-detect from JSON structure: {"providers": {"cms": {...}, "forum": {...}}}
        if ($format === 'json') {
            try {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return [];
            }

            if (isset($decoded['providers']) && is_array($decoded['providers'])) {
                return array_values(array_filter(
                    array_keys($decoded['providers']),
                    fn(string|int $key) => is_string($key) && $this->registry->has($key),
                ));
            }
        }

        return [];
    }

    /**
     * Extract the content slice for a specific provider from a combined export.
     */
    private function extractProviderContent(string $content, string $providerName, string $format): string
    {
        if ($format !== 'json') {
            return $content;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        $providers = is_array($decoded['providers'] ?? null) ? $decoded['providers'] : [];

        if (isset($providers[$providerName]) && is_array($providers[$providerName])) {
            return json_encode($providers[$providerName], JSON_THROW_ON_ERROR);
        }

        return $content;
    }
}
