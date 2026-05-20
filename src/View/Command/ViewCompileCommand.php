<?php

declare(strict_types=1);

namespace Pulsar\View\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\View\Engine\TemplateCompiler;
use Pulsar\View\ViewConfig;
use Pulsar\View\ViewException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function count;
use function file_put_contents;
use function is_dir;
use function is_string;
use function json_encode;
use function microtime;
use function number_format;
use function sort;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Pre-compiles all templates during build/deploy step.
 *
 * In production, the compiled cache directory is deployed as a build artifact
 * so that no runtime compilation is needed.
 */
#[Internal(reason: 'CLI command implementation')]
final class ViewCompileCommand extends Command
{
    public function __construct(
        private readonly TemplateCompiler $compiler,
        private readonly ViewConfig $config,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'view:compile';
        $this->description = 'Pre-compile all templates to the cache directory';
        $this->addOption('force', 'Recompile all templates even if cached', '-f');
        $this->addOption('manifest', 'Write a JSON manifest of compiled templates to the given path', '-m');
        $this->addOption('verify', 'Verify all templates are precompiled (exit 1 if any need compilation)', '-V');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // Verify mode: check that all templates are already compiled
        if ($input->getOption('verify') !== null) {
            return $this->executeVerify($output);
        }

        $output->info('Compiling templates...');

        $startTime = microtime(true);
        $compiled = 0;
        $skipped = 0;
        $errors = 0;
        /** @var list<string> $compiledNames */
        $compiledNames = [];

        foreach ($this->config->templatePaths as $basePath) {
            if (!is_dir($basePath)) {
                $output->warning(sprintf('Template path does not exist: %s', $basePath));

                continue;
            }

            $templates = $this->findTemplates($basePath);

            foreach ($templates as $templateName) {
                try {
                    $force = $input->getOption('force') !== null;

                    if (!$force && !$this->compiler->needsRecompilation($templateName)) {
                        $skipped++;
                        $compiledNames[] = $templateName;

                        continue;
                    }

                    $this->compiler->compile($templateName);
                    $compiled++;
                    $compiledNames[] = $templateName;
                } catch (ViewException $e) {
                    $errors++;
                    $output->error(sprintf('  Error: %s: %s', $templateName, $e->getMessage()));
                }
            }
        }

        $elapsed = microtime(true) - $startTime;

        $output->info(sprintf(
            'Compilation complete: %d compiled, %d skipped, %d errors in %ss',
            $compiled,
            $skipped,
            $errors,
            number_format($elapsed, 3),
        ));

        if ($errors > 0) {
            $output->error(sprintf('%d template(s) failed to compile', $errors));

            return ExitCode::Error->value;
        }

        // Write manifest if requested
        $manifestPath = $input->getNullableStringOption('manifest');

        if ($manifestPath !== null) {
            $this->writeManifest($manifestPath, $compiledNames, $output);
        }

        $total = $compiled + $skipped;
        $output->success(sprintf(
            'All %d template(s) are compiled and cached at: %s',
            $total,
            $this->config->cachePath,
        ));

        return ExitCode::Success->value;
    }

    /**
     * Verify that all templates are precompiled (no runtime compilation needed).
     */
    private function executeVerify(OutputInterface $output): int
    {
        $output->info('Verifying template precompilation...');

        /** @var list<string> $stale */
        $stale = [];

        foreach ($this->config->templatePaths as $basePath) {
            if (!is_dir($basePath)) {
                continue;
            }

            foreach ($this->findTemplates($basePath) as $templateName) {
                if ($this->compiler->needsRecompilation($templateName)) {
                    $stale[] = $templateName;
                }
            }
        }

        if ($stale === []) {
            $output->success('All templates are precompiled. Zero runtime compilation needed.');

            return ExitCode::Success->value;
        }

        $output->error(sprintf('%d template(s) need compilation:', count($stale)));

        foreach ($stale as $name) {
            $output->error(sprintf('  - %s', $name));
        }

        return ExitCode::Error->value;
    }

    /**
     * Write a JSON manifest of all compiled template names.
     *
     * @param list<string> $templateNames
     */
    private function writeManifest(string $path, array $templateNames, OutputInterface $output): void
    {
        sort($templateNames);

        $manifest = json_encode([
            'templates' => $templateNames,
            'count' => count($templateNames),
            'cache_path' => $this->config->cachePath,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        file_put_contents($path, $manifest);
        $output->info(sprintf('Manifest written to: %s', $path));
    }

    /**
     * Find all Pulse template files (.pulse.php) in a directory.
     *
     * @return list<string> Template names (dot-notation)
     */
    private function findTemplates(string $basePath): array
    {
        $templates = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $filename = $file->getFilename();

            if (str_ends_with($filename, '.pulse.php')) {
                $relativePath = substr($file->getPathname(), strlen($basePath) + 1);
                $name = substr($relativePath, 0, -10); // strlen('.pulse.php') = 10
                $name = str_replace([DIRECTORY_SEPARATOR, '/'], '.', $name);
                $templates[] = $name;
            }
        }

        return $templates;
    }
}
