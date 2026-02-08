<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Pulsar\Console\OutputInterface;

use function sprintf;

/**
 * Shared filesystem scaffolding helpers for scaffold commands.
 */
trait ScaffoldTrait
{
    /**
     * Create directories and report progress.
     *
     * @param list<string> $directories Relative directory paths ('' for root)
     * @return bool True if all directories were created successfully
     */
    private function createDirectories(
        string $basePath,
        string $rootLabel,
        array $directories,
        OutputInterface $output,
    ): bool {
        foreach ($directories as $dir) {
            $path = $basePath . ($dir !== '' ? DIRECTORY_SEPARATOR . $dir : '');
            if (!mkdir($path, 0o755, true)) {
                $output->errorln('Failed to create directory: ' . $path);
                return false;
            }
            $output->writeln(sprintf('  Created %s/', $dir !== '' ? $dir : $rootLabel));
        }

        return true;
    }

    /**
     * Write files and report progress.
     *
     * @param array<string, string> $files Relative file path => content
     */
    private function writeFiles(string $basePath, array $files, OutputInterface $output): void
    {
        foreach ($files as $file => $content) {
            $filePath = $basePath . DIRECTORY_SEPARATOR . $file;
            file_put_contents($filePath, $content);
            $output->writeln(sprintf('  Created %s', $file));
        }
    }

    /**
     * Convert a name to PascalCase.
     */
    private function toPascalCase(string $name): string
    {
        return str_replace([' ', '-'], '', ucwords(str_replace(['_', '-'], ' ', $name)));
    }

    /**
     * Convert a name to kebab-case.
     */
    private function toKebabCase(string $name): string
    {
        $name = preg_replace('/[A-Z]/', '-$0', $name) ?? $name;
        $name = strtolower(trim($name, '-'));

        return str_replace(['_', ' '], '-', $name);
    }

    /**
     * Convert a name to camelCase.
     */
    private function toCamelCase(string $name): string
    {
        return lcfirst($this->toPascalCase($name));
    }

    /**
     * Convert a name to snake_case.
     */
    private function toSnakeCase(string $name): string
    {
        $name = preg_replace('/[A-Z]/', '_$0', $name) ?? $name;

        return strtolower(trim($name, '_'));
    }

    /**
     * Resolve a base path from an option, falling back to a default relative to cwd.
     *
     * @return string|false The resolved absolute path, or false if cwd is unavailable
     */
    private function resolveBasePath(
        string $optionValue,
        string $default,
    ): string|false {
        $path = $optionValue !== '' ? $optionValue : $default;

        $cwd = getcwd();
        if ($cwd === false) {
            return false;
        }

        return $cwd . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
