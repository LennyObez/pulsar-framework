<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\NewProject;

use function is_dir;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\OutputInterface;
use Random\RandomException;
use RuntimeException;

use function sprintf;

/**
 * Orchestrates the full project generation pipeline.
 *
 * Flow:
 *  1. Validate that the target path does not already exist.
 *  2. Create the directory structure via {@see DirectoryLayout}.
 *  3. Generate a `.env` file via {@see SecureEnvGenerator}.
 *  4. Collect template files via {@see TemplateRegistry}.
 *  5. Write all files to disk.
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
     * @throws RuntimeException If the target path already exists
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
        // 1. Validate target does not exist
        if (is_dir($targetPath)) {
            throw new RuntimeException(sprintf(
                'Directory "%s" already exists. Choose a different name or remove it first.',
                $targetPath,
            ));
        }

        $output->writeln(sprintf('Creating project "%s" (%s preset, %s env)', $name, $preset->value, $env->value));
        $output->newLine();

        // 2. Create directory structure
        $directories = DirectoryLayout::forPreset($preset);
        $output->writeln('Directories:');

        if (!$this->createDirectories($targetPath, $name, ['', ...$directories], $output)) {
            throw new RuntimeException('Failed to create project directory structure.');
        }

        $output->newLine();

        // 3. Generate .env
        $envContent = $this->envGenerator->generate($name, $env);

        // 4. Collect template files
        $files = $this->templateRegistry->getFiles($name, $preset);

        // 5. Write all files (.env + templates)
        $allFiles = ['.env' => $envContent, ...$files];

        $output->writeln('Files:');
        $this->writeFiles($targetPath, $allFiles, $output);
    }
}
