<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\NewProject;

use Pulsar\Api\Internal;
use Pulsar\Console\OutputInterface;
use RuntimeException;

use function array_keys;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function sprintf;
use function str_replace;

use const DIRECTORY_SEPARATOR;

/**
 * Applies a control pack to a generated project directory.
 *
 * Copies template files from the pack, substituting placeholders for
 * project-specific values. Uses safe string replacement (no eval).
 */
#[Internal]
final readonly class PackInstaller
{
    private PackLoader $loader;

    public function __construct(?PackLoader $loader = null)
    {
        $this->loader = $loader ?? new PackLoader();
    }

    /**
     * Install a control pack into the target project directory.
     *
     * @param string          $packName   Pack identifier (e.g., "banking")
     * @param string          $projectName Project name for variable substitution
     * @param string          $targetPath Absolute path to the project root
     * @param OutputInterface $output     Output interface for progress reporting
     *
     * @throws RuntimeException If the pack cannot be loaded or installed
     */
    public function install(
        string $packName,
        string $projectName,
        string $targetPath,
        OutputInterface $output,
    ): void {
        $manifest = $this->loader->load($packName);
        $packDir = $this->loader->packDirectory($packName);

        $output->writeln(sprintf('Installing control pack: %s v%s', $manifest->name, $manifest->version));
        $output->writeln(sprintf('  %s', $manifest->description));
        $output->newLine();

        $namespace = $this->deriveNamespace($projectName);
        $variables = $this->buildVariables($projectName, $namespace);

        // Copy and process template files
        $this->copyPackFiles($packDir, $manifest, $targetPath, $variables, $output);

        // Copy static files (CONTROLS.md, NOT-CERTIFIED.md, docs/)
        $this->copyStaticFiles($packDir, $targetPath, $output);

        $output->newLine();
        $output->writeln(sprintf(
            'Control pack "%s" installed. See CONTROLS.md and NOT-CERTIFIED.md for compliance details.',
            $manifest->name,
        ));
    }

    /**
     * Copy template files from the pack manifest, substituting variables.
     *
     * @param array<string, string> $variables Template variable replacements
     */
    private function copyPackFiles(
        string $packDir,
        PackManifest $manifest,
        string $targetPath,
        array $variables,
        OutputInterface $output,
    ): void {
        $output->writeln('Pack files:');

        foreach ($manifest->files as $source => $target) {
            $sourcePath = $packDir . DIRECTORY_SEPARATOR . $source;

            if (!is_file($sourcePath)) {
                $output->writeln(sprintf('  Skipped %s (not found)', $source));
                continue;
            }

            $content = file_get_contents($sourcePath);

            if ($content === false) {
                $output->writeln(sprintf('  Skipped %s (unreadable)', $source));
                continue;
            }

            // Substitute template variables
            $processedContent = $this->substituteVariables($content, $variables);
            $processedTarget = $this->substituteVariables($target, $variables);

            $targetFile = $targetPath . DIRECTORY_SEPARATOR . $processedTarget;
            $targetDir = dirname($targetFile);

            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0o755, true);
            }

            file_put_contents($targetFile, $processedContent);
            $output->writeln(sprintf('  Created %s', $processedTarget));
        }
    }

    /**
     * Copy static documentation files from the pack directory.
     */
    private function copyStaticFiles(
        string $packDir,
        string $targetPath,
        OutputInterface $output,
    ): void {
        $staticFiles = ['CONTROLS.md', 'NOT-CERTIFIED.md'];

        foreach ($staticFiles as $file) {
            $sourcePath = $packDir . DIRECTORY_SEPARATOR . $file;

            if (!is_file($sourcePath)) {
                continue;
            }

            $content = file_get_contents($sourcePath);

            if ($content === false) {
                continue;
            }

            $targetFile = $targetPath . DIRECTORY_SEPARATOR . $file;
            file_put_contents($targetFile, $content);
            $output->writeln(sprintf('  Created %s', $file));
        }

        // Copy docs/ directory if it exists
        $docsDir = $packDir . DIRECTORY_SEPARATOR . 'docs';

        if (is_dir($docsDir)) {
            $this->copyDirectory($docsDir, $targetPath . DIRECTORY_SEPARATOR . 'docs', $output);
        }
    }

    /**
     * Recursively copy a directory.
     */
    private function copyDirectory(
        string $source,
        string $target,
        OutputInterface $output,
    ): void {
        if (!is_dir($target)) {
            mkdir($target, 0o755, true);
        }

        $entries = scandir($source);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $sourcePath = $source . DIRECTORY_SEPARATOR . $entry;
            $targetPath = $target . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $targetPath, $output);
            } elseif (is_file($sourcePath)) {
                $content = file_get_contents($sourcePath);

                if ($content !== false) {
                    file_put_contents($targetPath, $content);
                    $output->writeln(sprintf('  Created docs/%s', $entry));
                }
            }
        }
    }

    /**
     * Substitute template variables in content using safe string replacement.
     *
     * Supported variables: {{project_name}}, {{namespace}}, {{project_slug}}
     *
     * @param array<string, string> $variables Map of placeholder => replacement
     */
    private function substituteVariables(string $content, array $variables): string
    {
        $search = array_keys($variables);

        return str_replace($search, $variables, $content);
    }

    /**
     * Build the variable substitution map for a project.
     *
     * @return array<string, string>
     */
    private function buildVariables(string $projectName, string $namespace): array
    {
        return [
            '{{project_name}}' => $projectName,
            '{{namespace}}' => $namespace,
            '{{project_slug}}' => $this->slugify($projectName),
        ];
    }

    /**
     * Derive a PSR-4 namespace from a project name.
     */
    private function deriveNamespace(string $projectName): string
    {
        $parts = preg_split('/[-_\s]+/', $projectName);

        if ($parts === false) {
            return 'App';
        }

        return implode('', array_map(ucfirst(...), $parts));
    }

    /**
     * Convert a project name to a lowercase, hyphenated slug.
     */
    private function slugify(string $name): string
    {
        $hyphenated = preg_replace('/[A-Z]/', '-$0', $name) ?? $name;
        $slug = strtolower(trim($hyphenated, '-'));
        $slug = str_replace(['_', ' '], '-', $slug);

        return (string) preg_replace('/-+/', '-', $slug);
    }
}
