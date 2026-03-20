<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Filesystem\SafeFilesystem;
use Pulsar\Filesystem\SafePath;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function getcwd;
use function is_dir;
use function is_file;
use function is_int;
use function is_string;
use function ltrim;
use function realpath;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

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
            if (!mkdir($path, 0o750, true)) {
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
     * Validate and resolve module context from input.
     *
     * @return array{string, string, string, string}|int Tuple of [name, module, modulePath, namespace] or exit code
     */
    private function resolveModuleContext(
        InputInterface $input,
        OutputInterface $output,
        string $nameLabel,
    ): array|int {
        $name = $input->getArgument(0);
        $module = $input->getOption('module');
        $basePath = $input->getOption('path', 'app/Modules');

        if (!is_string($name) || $name === '') {
            $output->errorln($nameLabel . ' name is required.');
            return ExitCode::Invalid->value;
        }

        if (!is_string($module) || $module === '') {
            $output->errorln('Module name is required (--module).');
            return ExitCode::Invalid->value;
        }

        if (!is_string($basePath)) {
            $basePath = 'app/Modules';
        }

        $name = $this->toPascalCase($name);
        $module = $this->toPascalCase($module);

        $resolved = $this->resolveBasePathOrFail($basePath, 'app/Modules', $output);
        if (is_int($resolved)) {
            return $resolved;
        }

        $modulePath = $resolved . DIRECTORY_SEPARATOR . $module;

        if (!is_dir($modulePath)) {
            $output->errorln(sprintf('Module "%s" does not exist at %s', $module, $modulePath));
            return ExitCode::Error->value;
        }

        $namespace = 'App\\Modules\\' . $module;

        return [$name, $module, $modulePath, $namespace];
    }

    /**
     * Parse a comma-separated option value into a list of trimmed, non-empty strings.
     *
     * @return list<string>
     */
    private function parseCommaSeparatedOption(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            trim(...),
            explode(',', $value),
        )));
    }

    /**
     * Resolve test base path and create required subdirectories.
     *
     * @param list<string> $subdirs Subdirectories to create under the test base
     * @return string|false The test base path, or false if cwd is unavailable
     */
    private function resolveTestBasePath(string $module, array $subdirs): string|false
    {
        $cwd = getcwd();
        if ($cwd === false) {
            return false;
        }

        $testBase = $cwd . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit'
            . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . $module;

        foreach ($subdirs as $dir) {
            $fullDir = $testBase . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($fullDir)) {
                mkdir($fullDir, 0o750, true);
            }
        }

        return $testBase;
    }

    /**
     * Resolve a base path from an option, falling back to a default relative to cwd.
     *
     * F3.3: rejects path-traversal (`..`), absolute paths, and NUL
     * truncation by routing through {@see SafePath::resolveUnderCwd()}.
     * The returned string is guaranteed to live under the project's
     * current working directory.
     *
     * @return string|false The resolved absolute path, or false if
     *                      cwd is unavailable or the path is rejected
     */
    private function resolveBasePath(
        string $optionValue,
        string $default,
    ): string|false {
        $path = $optionValue !== '' ? $optionValue : $default;

        $safePath = SafePath::resolveUnderCwd($path);
        if ($safePath === null) {
            return false;
        }

        return $safePath->absolute;
    }

    /**
     * Resolve a base path or write an error and return an exit code.
     *
     * @return string|int The resolved absolute path, or ExitCode::Error value on failure
     */
    private function resolveBasePathOrFail(
        string $optionValue,
        string $default,
        OutputInterface $output,
    ): string|int {
        $resolved = $this->resolveBasePath($optionValue, $default);
        if ($resolved === false) {
            $output->errorln('Failed to get current working directory.');
            return ExitCode::Error->value;
        }

        return $resolved;
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
     * F3.3: delegates to {@see SafeFilesystem::removeDirectoryRecursive()}
     * via a {@see SafePath} chokepoint. The destructive primitives
     * (file remove, directory remove) live behind Symfony's
     * Filesystem component (a secure-by-default library) and the
     * SafePath value object guarantees no path can escape the cwd
     * trust boundary via `..`, absolute prefix, or mid-tree symlink.
     */
    private function removeDirectoryRecursive(string $dir): void
    {
        $safe = SafePath::resolveUnderCwd(self::relativeFromCwd($dir));
        if ($safe === null) {
            return;
        }

        new SafeFilesystem()->removeDirectoryRecursive($safe);
    }

    /**
     * Remove a single file and report it.
     *
     * F3.3: delegates to {@see SafeFilesystem::removeFile()}.
     */
    private function removeFileWithOutput(string $path, OutputInterface $output): void
    {
        if (!is_file($path)) {
            return;
        }

        $safe = SafePath::resolveUnderCwd(self::relativeFromCwd($path));
        if ($safe === null) {
            $output->errorln(sprintf('  Refused to remove %s (outside project root)', $path));
            return;
        }

        new SafeFilesystem()->removeFile($safe);
        $output->writeln(sprintf('  Removed %s', $path));
    }

    /**
     * List all files in a directory recursively.
     *
     * @return list<string>
     */
    private function listFilesRecursive(string $dir): array
    {
        $safe = SafePath::resolveUnderCwd(self::relativeFromCwd($dir));
        if ($safe === null) {
            return [];
        }

        $files = [];
        foreach (new SafeFilesystem()->listFilesRecursive($safe) as $file) {
            $files[] = $file->absolute;
        }

        return $files;
    }

    /**
     * F3.3: convert an absolute path back to a cwd-relative form so
     * {@see SafePath::resolveUnderCwd()} can re-validate it. Callers
     * already pass paths derived from `resolveBasePath()` (which
     * itself goes through SafePath), so this strip-and-revalidate
     * is defence-in-depth — any caller bypassing the canonical
     * factory still hits the validation chokepoint.
     */
    private static function relativeFromCwd(string $absolutePath): string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            return $absolutePath;
        }

        $cwdReal = realpath($cwd);
        $pathReal = realpath($absolutePath);

        if ($cwdReal !== false && $pathReal !== false && str_starts_with($pathReal, $cwdReal)) {
            $stripped = substr($pathReal, strlen($cwdReal));
            return ltrim($stripped, '/\\');
        }

        // realpath fails on non-existent paths — fall back to a
        // string strip when the prefix matches naively. SafePath
        // re-validates, so an unsafe path here still gets rejected.
        if (str_starts_with($absolutePath, $cwd)) {
            $stripped = substr($absolutePath, strlen($cwd));
            return ltrim($stripped, '/\\');
        }

        return $absolutePath;
    }
}
