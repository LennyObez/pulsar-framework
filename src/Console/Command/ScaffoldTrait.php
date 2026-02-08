<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Pulsar\Console\OutputInterface;

use function sprintf;

/**
 * Shared filesystem scaffolding helpers for scaffold and removal commands.
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

    /**
     * Prompt the user for confirmation before a destructive operation.
     *
     * @param resource $stdin Readable stream for interactive input
     */
    private function confirmAction(mixed $stdin, OutputInterface $output, string $message): bool
    {
        $output->write($message . ' [y/N] ');

        $answer = fgets($stdin);

        if ($answer === false) {
            return false;
        }

        return strtolower(trim($answer)) === 'y';
    }

    /**
     * Recursively remove a directory and all its contents.
     *
     * Includes retry logic for environments (e.g. Windows/OneDrive) where
     * file handles may not be released immediately after unlink()/rmdir().
     */
    private function removeDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectoryRecursive($path);
            } else {
                @unlink($path);
            }
        }

        // Release scandir handle references so Windows can free the directory
        unset($items);
        gc_collect_cycles();
        clearstatcache(true, $dir);

        $this->rmdirWithRetry($dir);
    }

    /**
     * Remove a directory with retry logic for Windows/OneDrive handle locking.
     */
    private function rmdirWithRetry(string $dir): void
    {
        // Escalating delays: 100ms, 200ms, 400ms, 800ms, 1600ms (~3.1s total)
        $delays = [100_000, 200_000, 400_000, 800_000, 1_600_000];

        foreach ($delays as $delay) {
            if (@rmdir($dir)) {
                return;
            }
            usleep($delay);
            clearstatcache(true, $dir);
        }

        // Final attempt — let the warning through if it still fails
        @rmdir($dir);
    }

    /**
     * Remove a single file and report it.
     */
    private function removeFileWithOutput(string $path, OutputInterface $output): void
    {
        if (!is_file($path)) {
            return;
        }

        unlink($path);
        $output->writeln(sprintf('  Removed %s', $path));
    }

    /**
     * List all files in a directory recursively.
     *
     * @return list<string>
     */
    private function listFilesRecursive(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $items = scandir($dir);
        if ($items === false) {
            return [];
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $files = [...$files, ...$this->listFilesRecursive($path)];
            } else {
                $files[] = $path;
            }
        }

        return $files;
    }
}
