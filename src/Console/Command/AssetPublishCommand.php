<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Api\Api;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use RuntimeException;

use function copy;
use function is_dir;
use function is_link;
use function mkdir;
use function realpath;
use function scandir;
use function sprintf;
use function str_starts_with;
use function symlink;

use const DIRECTORY_SEPARATOR;

/**
 * Publish project resource directories to the public web root.
 *
 * Creates symlinks from resources/views/, resources/css/, and resources/lang/
 * into public/assets/ so the web server can serve them directly.
 *
 * Usage:
 *   pulsar asset:publish              Symlink all resource directories
 *   pulsar asset:publish --force      Overwrite existing symlinks
 *   pulsar asset:publish --copy       Copy files instead of symlinking
 * @api
 */
#[Api(since: '1.0.0')]
final class AssetPublishCommand extends Command
{
    /** @var array<string, string> Source dir (relative to resources/) => target dir (relative to public/assets/) */
    private const array DEFAULT_MAPPINGS = [
        'views' => 'views',
        'css' => 'css',
        'lang' => 'lang',
    ];

    public function __construct(
        private readonly string $basePath,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'asset:publish';
        $this->description = 'Publish resource directories to public/assets via symlinks';

        $this->addOption('force', 'Overwrite existing symlinks or directories', '-f');
        $this->addOption('copy', 'Copy files instead of creating symlinks', '-c');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $resourcesDir = $this->basePath . DIRECTORY_SEPARATOR . 'resources';
        $targetDir = $this->basePath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets';
        $force = $input->hasOption('force');
        $copy = $input->hasOption('copy');

        if (!is_dir($resourcesDir)) {
            $output->errorln('Resources directory not found: ' . $resourcesDir);
            return ExitCode::Error->value;
        }

        // Ensure public/assets/ exists
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0o750, true)) {
                $output->errorln('Failed to create target directory: ' . $targetDir);
                return ExitCode::Error->value;
            }
        }

        $published = 0;
        $skipped = 0;

        foreach (self::DEFAULT_MAPPINGS as $sourceSubdir => $targetSubdir) {
            $sourcePath = $resourcesDir . DIRECTORY_SEPARATOR . $sourceSubdir;
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $targetSubdir;

            if (!is_dir($sourcePath)) {
                $output->info(sprintf('Skipped %s (directory does not exist)', $sourceSubdir));
                $skipped++;
                continue;
            }

            // Handle existing target (only symlinks can be replaced; real dirs require manual cleanup)
            if (is_link($targetPath)) {
                if (!$force) {
                    $output->info(sprintf('Skipped %s (symlink already exists, use --force to overwrite)', $sourceSubdir));
                    $skipped++;
                    continue;
                }
                self::removeBoundedSymlink($targetPath, $targetDir);
            } elseif (is_dir($targetPath)) {
                if (!$force) {
                    $output->info(sprintf('Skipped %s (directory already exists, use --force to overwrite)', $sourceSubdir));
                    $skipped++;
                    continue;
                }
                // For --copy mode, prior copied dirs need removal before re-copy.
                // Use a bounded recursive delete that validates every path.
                self::removeBoundedTree($targetPath, $targetDir);
            }

            if ($copy) {
                self::copyDirectory($sourcePath, $targetPath);
                $output->success(sprintf('Copied %s -> public/assets/%s', $sourceSubdir, $targetSubdir));
            } else {
                $realSource = realpath($sourcePath);
                if ($realSource === false) {
                    $output->errorln(sprintf('Failed to resolve path: %s', $sourcePath));
                    continue;
                }
                if (!symlink($realSource, $targetPath)) {
                    $output->errorln(sprintf('Failed to create symlink: %s -> %s', $targetPath, $realSource));
                    continue;
                }
                $output->success(sprintf('Linked %s -> public/assets/%s', $sourceSubdir, $targetSubdir));
            }

            $published++;
        }

        $output->writeln('');
        $output->writeln(sprintf('Published: %d, Skipped: %d', $published, $skipped));

        return ExitCode::Success->value;
    }

    /**
     * Remove a symlink only if it resides within the boundary directory.
     *
     * @throws RuntimeException If the symlink path escapes the boundary
     */
    private static function removeBoundedSymlink(string $linkPath, string $boundary): void
    {
        self::assertContained($linkPath, $boundary);

        // PHP's unlink() on a symlink removes the link, not its target.
        // nosemgrep: php.lang.security.unlink-use.unlink-use
        unlink($linkPath);
    }

    /**
     * Recursively remove a directory tree with strict containment validation.
     *
     * Every single path encountered during traversal is validated against the
     * boundary directory before any filesystem mutation. This prevents path
     * traversal even if the directory tree contains crafted entries.
     *
     * @throws RuntimeException If any path escapes the boundary
     */
    private static function removeBoundedTree(string $dir, string $boundary): void
    {
        $realDir = realpath($dir);
        if ($realDir === false) {
            return;
        }

        self::assertContained($realDir, $boundary);

        $entries = scandir($realDir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $realDir . DIRECTORY_SEPARATOR . $entry;

            // Resolve the real path for each child to defeat symlink-based traversal
            $realChild = is_link($child) ? $child : (realpath($child) ?: $child);
            self::assertContained($realChild, $boundary);

            if (is_dir($child) && !is_link($child)) {
                self::removeBoundedTree($child, $boundary);
            } else {
                // nosemgrep: php.lang.security.unlink-use.unlink-use
                unlink($child);
            }
        }

        rmdir($realDir);
    }

    /**
     * Recursively copy a directory.
     */
    private static function copyDirectory(string $source, string $target): void
    {
        mkdir($target, 0o750, true);

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
                self::copyDirectory($sourcePath, $targetPath);
            } else {
                copy($sourcePath, $targetPath);
            }
        }
    }

    /**
     * Assert that a resolved path is contained within the boundary directory.
     *
     * Uses realpath() for non-symlink paths to normalize traversal sequences
     * (e.g., `../`). For symlinks, checks the link location itself.
     *
     * @throws RuntimeException If the path escapes the boundary
     */
    private static function assertContained(string $path, string $boundary): void
    {
        $normalizedBoundary = realpath($boundary) ?: $boundary;

        if (!str_starts_with($path, $normalizedBoundary)) {
            throw new RuntimeException(sprintf(
                'Path traversal blocked: "%s" is outside boundary "%s"',
                $path,
                $boundary,
            ));
        }
    }
}
