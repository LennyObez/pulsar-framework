<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use function ltrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Shared path-normalization logic for integrity module classes.
 *
 * Expects the using class to expose `$this->basePath` as a `string` property.
 */
trait BasePathResolveTrait
{
    /**
     * Convert an absolute path to a path relative to the base directory.
     *
     * Always uses forward slashes for consistent cross-platform paths.
     */
    private function toRelativePath(string $absolutePath): string
    {
        $normalized = str_replace('\\', '/', $absolutePath);
        $normalizedBase = str_replace('\\', '/', $this->basePath);

        if (str_starts_with($normalized, $normalizedBase . '/')) {
            return ltrim(substr($normalized, strlen($normalizedBase)), '/');
        }

        return $normalized;
    }
}
