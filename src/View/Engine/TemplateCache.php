<?php

declare(strict_types=1);

namespace Pulsar\View\Engine;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\View\ViewException;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function hash;
use function is_dir;
use function is_file;
use function mkdir;
use function sprintf;
use function str_replace;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const LOCK_EX;

/**
 * Manages compiled template caching with content-hash validation.
 *
 * Templates are cached as PHP files keyed by their content hash (SHA-256).
 * This ensures that identical template content always maps to the same
 * cache entry, enabling deterministic builds.
 */
#[Internal(reason: 'Cache management is an engine implementation detail')]
final readonly class TemplateCache
{
    public function __construct(
        private string $cachePath,
    ) {}

    /**
     * Get the compiled template for a given template name and source content.
     *
     * Returns the CompiledTemplate if a valid cached version exists, or null
     * if the cache is missing or stale.
     *
     * @param string $templateName Logical template name
     * @param string $sourceContent Raw source content of the template
     */
    #[NoDiscard]
    public function get(string $templateName, string $sourceContent): ?CompiledTemplate
    {
        $sourceHash = self::hash($sourceContent);
        $compiledPath = $this->compiledPath($templateName, $sourceHash);

        // Read meta file directly: avoids two separate file_exists syscalls.
        // file_get_contents returns false when the file is missing.
        $meta = @file_get_contents($compiledPath . '.meta');

        if ($meta === false) {
            return null;
        }

        if (trim($meta) !== $sourceHash) {
            return null;
        }

        // filemtime returns false for missing files: doubles as existence check.
        $mtime = @filemtime($compiledPath);

        if ($mtime === false) {
            return null;
        }

        return new CompiledTemplate(
            compiledPath: $compiledPath,
            sourceHash: $sourceHash,
            compiledAt: $mtime,
        );
    }

    /**
     * Store compiled output in the cache.
     *
     * @param string $templateName Logical template name
     * @param string $sourceContent Raw source content of the template
     * @param string $compiledOutput The compiled PHP code
     *
     * @throws ViewException If the cache directory cannot be created or the file cannot be written
     */
    public function put(string $templateName, string $sourceContent, string $compiledOutput): CompiledTemplate
    {
        $sourceHash = self::hash($sourceContent);
        $compiledPath = $this->compiledPath($templateName, $sourceHash);

        $dir = dirname($compiledPath);

        if (!is_dir($dir) && !@mkdir($dir, 0o755, true)) {
            throw ViewException::cacheWriteFailed($compiledPath);
        }

        $written = file_put_contents($compiledPath, $compiledOutput, LOCK_EX);

        if ($written === false) {
            throw ViewException::cacheWriteFailed($compiledPath);
        }

        $metaWritten = file_put_contents($compiledPath . '.meta', $sourceHash, LOCK_EX);

        if ($metaWritten === false) {
            throw ViewException::cacheWriteFailed($compiledPath . '.meta');
        }

        return new CompiledTemplate(
            compiledPath: $compiledPath,
            sourceHash: $sourceHash,
            compiledAt: time(),
        );
    }

    /**
     * Check whether a valid cache entry exists for the given template and content.
     */
    #[NoDiscard]
    public function has(string $templateName, string $sourceContent): bool
    {
        return $this->get($templateName, $sourceContent) !== null;
    }

    /**
     * Remove the cached version of a template.
     *
     * @param string $templateName Logical template name
     * @param string $sourceHash Content hash of the version to remove
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function forget(string $templateName, string $sourceHash): void
    {
        $compiledPath = $this->compiledPath($templateName, $sourceHash);

        if (is_file($compiledPath)) {
            unlink($compiledPath);
        }

        $metaPath = $compiledPath . '.meta';

        if (is_file($metaPath)) {
            unlink($metaPath);
        }
    }

    /**
     * Remove all cached templates.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function flush(): void
    {
        if (!is_dir($this->cachePath)) {
            return;
        }

        $this->removeDirectory($this->cachePath, false);
    }

    /**
     * Compute the content-addressable hash for template source.
     */
    #[NoDiscard]
    public static function hash(string $content): string
    {
        return hash('sha256', $content);
    }

    /**
     * Build the filesystem path for a compiled template.
     */
    #[NoDiscard]
    private function compiledPath(string $templateName, string $sourceHash): string
    {
        // Absolute paths (from project template resolution): use a hash-based cache key
        // to avoid embedding filesystem paths in the cache directory structure.
        if (str_starts_with($templateName, '/') || (PHP_OS_FAMILY === 'Windows' && isset($templateName[1]) && $templateName[1] === ':')) {
            $safeName = 'abs_' . hash('xxh3', $templateName);
        } else {
            // Strip namespace prefix (e.g., "cms::admin.foo" -> "admin.foo")
            if (str_contains($templateName, '::')) {
                $parts = explode('::', $templateName, 2);
                $templateName = $parts[1] ?? $parts[0];
            }

            $safeName = str_replace(['.', '/', '\\'], DIRECTORY_SEPARATOR, $templateName);
        }

        return sprintf(
            '%s%s%s_%s.php',
            $this->cachePath,
            DIRECTORY_SEPARATOR,
            $safeName,
            substr($sourceHash, 0, 16),
        );
    }

    /**
     * Recursively remove directory contents.
     */
    private function removeDirectory(string $path, bool $removeSelf): void
    {
        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fullPath)) {
                $this->removeDirectory($fullPath, true);
            } else {
                unlink($fullPath);
            }
        }

        if ($removeSelf) {
            rmdir($path);
        }
    }
}
