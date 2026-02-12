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

use function is_dir;
use function microtime;
use function number_format;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

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
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->info('Compiling templates...');

        $startTime = microtime(true);
        $compiled = 0;
        $skipped = 0;
        $errors = 0;

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

                        continue;
                    }

                    $this->compiler->compile($templateName);
                    $compiled++;
                } catch (ViewException $e) {
                    $errors++;
                    $output->error(sprintf('  Error: %s — %s', $templateName, $e->getMessage()));
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

        $total = $compiled + $skipped;
        $output->success(sprintf(
            'All %d template(s) are compiled and cached at: %s',
            $total,
            $this->config->cachePath,
        ));

        return ExitCode::Success->value;
    }

    /**
     * Find all .pulsar.php template files in a directory.
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

            if (!str_ends_with($filename, '.pulsar.php')) {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($basePath) + 1);
            // Remove .pulsar.php extension and convert separators to dots
            $name = substr($relativePath, 0, -11); // strlen('.pulsar.php') = 11
            $name = str_replace([DIRECTORY_SEPARATOR, '/'], '.', $name);

            $templates[] = $name;
        }

        return $templates;
    }
}
