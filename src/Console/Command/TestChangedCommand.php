<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function implode;
use function is_string;
use function preg_match;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function trim;

/**
 * Run tests only for files changed since the last commit.
 *
 * Uses git diff to find changed PHP files, then maps source files
 * to their corresponding test files and runs only those tests.
 *
 * Usage: test:changed [--from=HEAD~1] [--dry-run]
 */
#[Internal]
final class TestChangedCommand extends Command
{
    /**
     * @param callable(string): string $shellExecutor Function that executes shell commands and returns output
     */
    public function __construct(
        private readonly mixed $shellExecutor,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'test:changed';
        $this->description = 'Run tests for files changed since last commit';
        $this->addOption('from', 'Git ref to diff from', null, 'HEAD');
        $this->addOption('dry-run', 'Show which tests would run without executing them');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $from = $input->getOption('from', 'HEAD');
        $dryRun = $input->hasOption('dry-run');

        if (!is_string($from)) {
            $from = 'HEAD';
        }

        $output->writeln(sprintf('Finding changed files since %s...', $from));

        $changedFiles = $this->getChangedFiles($from);

        if ($changedFiles === []) {
            $output->writeln('No PHP files changed.');
            return ExitCode::Success->value;
        }

        $output->writeln(sprintf('Found %d changed PHP file(s).', count($changedFiles)));
        $output->newLine();

        $testFiles = $this->mapToTestFiles($changedFiles);

        if ($testFiles === []) {
            $output->writeln('No corresponding test files found for the changed files.');
            return ExitCode::Success->value;
        }

        $output->writeln(sprintf('Tests to run (%d):', count($testFiles)));

        foreach ($testFiles as $testFile) {
            $output->writeln(sprintf('  %s', $testFile));
        }

        if ($dryRun) {
            $output->newLine();
            $output->info('Dry run: no tests executed.');
            return ExitCode::Success->value;
        }

        $output->newLine();
        $output->writeln('Running tests...');
        $output->newLine();

        $filterArg = implode('|', array_map($this->extractTestClassName(...), $testFiles));
        $command = sprintf(
            'vendor/bin/phpunit -c tools/php/phpunit.xml --filter "%s"',
            $filterArg,
        );

        $result = ($this->shellExecutor)($command);
        $output->writeln($result);

        // Check for failure indicators in the output
        if (str_contains($result, 'FAILURES!') || str_contains($result, 'ERRORS!')) {
            return ExitCode::Error->value;
        }

        return ExitCode::Success->value;
    }

    /**
     * Get PHP files changed relative to the given ref.
     *
     * @return list<string>
     */
    private function getChangedFiles(string $from): array
    {
        $command = sprintf('git diff --name-only --diff-filter=ACMR %s: "*.php"', $from);
        $result = ($this->shellExecutor)($command);

        if (trim($result) === '') {
            return [];
        }

        return array_values(array_filter(
            explode("\n", trim($result)),
            static fn(string $file): bool => str_ends_with($file, '.php'),
        ));
    }

    /**
     * Map source files to their corresponding test files.
     *
     * @param list<string> $changedFiles
     * @return list<string>
     */
    private function mapToTestFiles(array $changedFiles): array
    {
        $testFiles = [];

        foreach ($changedFiles as $file) {
            // If it's already a test file, include it directly
            if (str_ends_with($file, 'Test.php')) {
                $testFiles[] = $file;
                continue;
            }

            // Map src/ files to tests/Unit/
            if (str_starts_with($file, 'src/')) {
                $relative = substr($file, 4); // Remove 'src/'
                $testFile = 'tests/Unit/' . str_replace('.php', 'Test.php', $relative);
                $testFiles[] = $testFile;
                continue;
            }

            // Map extensions/*/src/ files to extensions/*/tests/Unit/
            if (preg_match('#^extensions/([^/]+)/src/(.+)$#', $file, $matches) === 1) {
                $ext = $matches[1];
                $relative = $matches[2];
                $testFile = 'extensions/' . $ext . '/tests/Unit/' . str_replace('.php', 'Test.php', $relative);
                $testFiles[] = $testFile;
            }
        }

        return array_values(array_unique($testFiles));
    }

    private function extractTestClassName(string $testFile): string
    {
        $basename = basename($testFile, '.php');
        return $basename;
    }
}
