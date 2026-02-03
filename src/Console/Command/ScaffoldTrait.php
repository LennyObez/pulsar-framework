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
}
