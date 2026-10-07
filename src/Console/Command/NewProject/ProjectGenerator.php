<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\NewProject;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\OutputInterface;
use Random\RandomException;
use RuntimeException;

use function array_keys;
use function implode;
use function is_dir;
use function is_file;
use function sprintf;
use function str_replace;

use const DIRECTORY_SEPARATOR;

/**
 * Orchestrates the full project generation pipeline.
 *
 * Flow:
 *  1. Collect every file the preset produces, environment file included.
 *  2. Refuse if any of them is already on disk.
 *  3. Create whatever of the {@see DirectoryLayout} structure is missing.
 *  4. Write the files.
 */
#[Internal]
final class ProjectGenerator
{
    use ScaffoldTrait;

    private readonly SecureEnvGenerator $envGenerator;
    private readonly TemplateRegistry $templateRegistry;

    public function __construct()
    {
        $this->envGenerator = new SecureEnvGenerator();
        $this->templateRegistry = new TemplateRegistry(
            new ComposerJsonGenerator(),
        );
    }

    /**
     * Generate a new project at the given path.
     *
     * The target directory may already exist. It used to be refused outright,
     * which left `pulsar init` unable to do the one thing its own help text
     * offers — "Target directory (default: current directory)" — because the
     * current directory always exists, so the bare command could not succeed
     * from anywhere. The same guard blocked the flow the install guide
     * describes, where `composer require pulsar/framework` has already put a
     * composer.json and a vendor/ in the directory before `init` runs.
     *
     * What must never happen is a scaffold writing over work that is already
     * there, so the guard moved from the directory to the files: one collision
     * refuses the whole run and names what it found. Refusing beats skipping —
     * a half-written project reporting success is the outcome this class exists
     * to avoid — and refusing before anything is created leaves the directory
     * exactly as it was found.
     *
     * @throws RuntimeException If any file the preset produces is already there
     * @throws JsonException If composer.json encoding fails
     * @throws RandomException If cryptographic random generation fails
     */
    public function generate(
        string $name,
        ProjectPreset $preset,
        EnvironmentPreset $env,
        string $targetPath,
        OutputInterface $output,
    ): void {
        // 1. Everything this run would write, environment file first.
        $envContent = $this->envGenerator->generate($name, $env);
        $files = $this->templateRegistry->getFiles($name, $preset);
        $allFiles = ['.env' => $envContent, ...$files];

        // 2. Refuse before touching the filesystem.
        $collisions = $this->collisions($targetPath, array_keys($allFiles));

        if ($collisions !== []) {
            throw new RuntimeException(sprintf(
                'Refusing to overwrite files that are already in "%s": %s. '
                . 'Remove them, or point the command at a directory that does not have them.',
                $targetPath,
                implode(', ', $collisions),
            ));
        }

        $output->writeln(sprintf('Creating project "%s" (%s preset, %s env)', $name, $preset->value, $env->value));
        $output->newLine();

        // 3. Create the parts of the layout that are missing.
        $output->writeln('Directories:');

        if (!$this->createMissingDirectories($targetPath, $name, $preset, $output)) {
            throw new RuntimeException('Failed to create project directory structure.');
        }

        $output->newLine();

        // 4. Write.
        $output->writeln('Files:');
        $this->writeFiles($targetPath, $allFiles, $output);
    }

    /**
     * The relative paths among these that are already on disk under the target.
     *
     * @param list<string> $relativePaths
     * @return list<string>
     */
    private function collisions(string $targetPath, array $relativePaths): array
    {
        if (!is_dir($targetPath)) {
            return [];
        }

        $found = [];

        foreach ($relativePaths as $relative) {
            if (is_file($targetPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative))) {
                $found[] = $relative;
            }
        }

        return $found;
    }

    /**
     * Create the parts of the layout that are not there yet.
     *
     * {@see ScaffoldTrait::createDirectories()} reports a failure for a directory
     * that already exists, because `mkdir()` returns false for one. That is right
     * for the scaffolders that own their whole target; here it would turn "the
     * directory you asked me to fill" into an error.
     */
    private function createMissingDirectories(
        string $targetPath,
        string $name,
        ProjectPreset $preset,
        OutputInterface $output,
    ): bool {
        $missing = [];

        foreach (['', ...DirectoryLayout::forPreset($preset)] as $relative) {
            $absolute = $targetPath . ($relative !== ''
                ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)
                : '');

            if (!is_dir($absolute)) {
                $missing[] = $relative;
            }
        }

        if ($missing === []) {
            $output->writeln('  (already present)');

            return true;
        }

        return $this->createDirectories($targetPath, $name, $missing, $output);
    }
}
