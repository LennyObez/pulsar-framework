<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Command;

use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Extension\Cms\Tools\ImportResult;

use function array_sum;
use function count;
use function file_exists;
use function file_get_contents;
use function glob;
use function is_dir;
use function is_string;
use function pathinfo;
use function sprintf;

use const GLOB_NOSORT;
use const PATHINFO_EXTENSION;

/**
 * Import CMS content from a JSON file, multiple files, or a directory.
 *
 * Supports three import modes:
 *   1. Single file: `pulsar cms:import site-content.json`
 *   2. Directory: `pulsar cms:import resources/import/` (imports all .json files)
 *   3. Auto-detect: inspects root keys to route to the correct handler
 *
 * Defaults to dry-run mode unless --execute is passed.
 *
 * @psalm-api Resolved by the console application from the DI
 *            container, registered under the `cms:import` signature.
 */
#[Internal]
final class CmsImportCommand extends Command
{
    public function __construct(
        private readonly ImportExportServiceInterface $importService,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'cms:import';
        $this->description = 'Import CMS content from a JSON file or directory';

        $this->addArgument('file', 'Path to a JSON file or directory to import', required: true);
        $this->addOption('execute', 'Persist changes (default is dry-run)');
        $this->addOption('site-definition', 'Treat the file as a full site definition (N.3 schema)');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = $input->getArgument(0);

        if (!is_string($filePath) || $filePath === '') {
            $output->error('Missing required argument: file path');

            return ExitCode::Invalid->value;
        }

        if (!file_exists($filePath)) {
            $output->error(sprintf('File not found: %s', $filePath));

            return ExitCode::Error->value;
        }

        $dryRun = !$input->hasOption('execute');
        $isSiteDefinition = $input->hasOption('site-definition');

        if ($dryRun) {
            $output->info('Dry-run mode (pass --execute to persist changes)');
        } else {
            $output->warning('Execute mode: changes will be persisted');
        }

        $output->newLine();

        // Directory mode: import all .json files
        if (is_dir($filePath)) {
            return $this->importDirectory($filePath, $dryRun, $output);
        }

        // Single file mode
        $content = file_get_contents($filePath);

        if ($content === false || $content === '') {
            $output->error(sprintf('Unable to read file or file is empty: %s', $filePath));

            return ExitCode::Error->value;
        }

        try {
            if ($isSiteDefinition) {
                $result = $this->importService->importSiteDefinition($content, $dryRun);
            } else {
                // Auto-detect mode: inspect root keys
                $result = $this->importService->importUnifiedFile($content, $dryRun);
            }
        } catch (JsonException $e) {
            $output->error(sprintf('Invalid JSON: %s', $e->getMessage()));

            return ExitCode::Error->value;
        }

        $this->renderResult($result, $output);

        if ($result->hasErrors()) {
            return ExitCode::Error->value;
        }

        $output->newLine();

        if ($dryRun) {
            $output->success('Dry-run complete. Pass --execute to persist changes.');
        } else {
            $output->success('Import completed successfully.');
        }

        return ExitCode::Success->value;
    }

    /**
     * Import all .json files from a directory in alphabetical order.
     */
    private function importDirectory(string $dirPath, bool $dryRun, OutputInterface $output): int
    {
        $files = glob($dirPath . '/*.json', GLOB_NOSORT);

        if ($files === false || $files === []) {
            $output->error(sprintf('No .json files found in directory: %s', $dirPath));

            return ExitCode::Error->value;
        }

        sort($files);

        $output->writeln(sprintf('Found %d JSON file(s) in directory', count($files)));
        $output->newLine();

        $combinedResult = new ImportResult(
            created: [],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: $dryRun,
        );

        $hasErrors = false;

        foreach ($files as $file) {
            $filename = pathinfo($file, PATHINFO_EXTENSION) === 'json' ? basename($file) : $file;
            $output->writeln(sprintf('Importing: %s', $filename));

            $content = file_get_contents($file);

            if ($content === false || $content === '') {
                $output->warning(sprintf('  Skipped (empty or unreadable): %s', $filename));

                continue;
            }

            try {
                $result = $this->importService->importUnifiedFile($content, $dryRun);
                $combinedResult = $combinedResult->merge($result);

                $fileCreated = array_sum($result->created);
                $fileUpdated = array_sum($result->updated);
                $output->writeln(sprintf('  Created: %d, Updated: %d', $fileCreated, $fileUpdated));

                if ($result->hasErrors()) {
                    $hasErrors = true;

                    foreach ($result->errors as $error) {
                        $output->error(sprintf('  Error: %s', $error));
                    }
                }
            } catch (JsonException $e) {
                $output->error(sprintf('  Invalid JSON in %s: %s', $filename, $e->getMessage()));
                $hasErrors = true;
            }
        }

        $output->newLine();
        $output->writeln('=== Combined Results ===');
        $this->renderResult($combinedResult, $output);

        if ($hasErrors) {
            return ExitCode::Error->value;
        }

        $output->newLine();

        if ($dryRun) {
            $output->success('Dry-run complete. Pass --execute to persist changes.');
        } else {
            $output->success('Import completed successfully.');
        }

        return ExitCode::Success->value;
    }

    private function renderResult(ImportResult $result, OutputInterface $output): void
    {
        $totalCreated = array_sum($result->created);
        $totalUpdated = array_sum($result->updated);
        $totalSkipped = array_sum($result->skipped);

        $output->writeln(sprintf('Created: %d', $totalCreated));

        foreach ($result->created as $type => $count) {
            if ($count > 0) {
                $output->writeln(sprintf('  %s: %d', $type, $count));
            }
        }

        $output->writeln(sprintf('Updated: %d', $totalUpdated));

        foreach ($result->updated as $type => $count) {
            if ($count > 0) {
                $output->writeln(sprintf('  %s: %d', $type, $count));
            }
        }

        $output->writeln(sprintf('Skipped: %d', $totalSkipped));

        foreach ($result->skipped as $type => $count) {
            if ($count > 0) {
                $output->writeln(sprintf('  %s: %d', $type, $count));
            }
        }

        if ($result->warnings !== []) {
            $output->newLine();
            $output->warning(sprintf('Warnings (%d):', count($result->warnings)));

            foreach ($result->warnings as $warning) {
                $output->writeln(sprintf('  - %s', $warning));
            }
        }

        if ($result->errors !== []) {
            $output->newLine();
            $output->error(sprintf('Errors (%d):', count($result->errors)));

            foreach ($result->errors as $error) {
                $output->writeln(sprintf('  - %s', $error));
            }
        }
    }
}
