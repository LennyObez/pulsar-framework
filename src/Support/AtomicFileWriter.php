<?php

declare(strict_types=1);

namespace Pulsar\Support;

use Pulsar\Api\Api;
use Random\RandomException;
use RuntimeException;

use function bin2hex;
use function dirname;
use function file_put_contents;
use function is_file;
use function random_bytes;
use function rename;
use function sprintf;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Atomic file writer using write-to-temp-then-rename.
 *
 * On Unix, rename() is atomic within the same filesystem.
 * On Windows, rename() fails if the target exists, so we unlink first.
 * A small race window exists on Windows: acceptable for build artifacts.
 */
#[Api(since: '1.0.0')]
final class AtomicFileWriter
{
    /**
     * Write content atomically to the given path.
     *
     * @throws RuntimeException If the write or rename fails
     * @throws RandomException If random byte generation fails
     */
    public static function write(string $path, string $content): void
    {
        $dir = dirname($path);
        $tmp = $dir . DIRECTORY_SEPARATOR . '.tmp.' . bin2hex(random_bytes(8));

        if (@file_put_contents($tmp, $content) === false) {
            throw new RuntimeException(sprintf('Failed to write temporary file: %s', $tmp));
        }

        try {
            if (PHP_OS_FAMILY === 'Windows' && is_file($path)) {
                if (!@unlink($path)) {
                    throw new RuntimeException(sprintf(
                        'Failed to remove existing file on Windows: %s',
                        $path,
                    ));
                }
            }

            if (!@rename($tmp, $path)) {
                throw new RuntimeException(sprintf(
                    'Failed to rename temporary file %s to %s',
                    $tmp,
                    $path,
                ));
            }
        } catch (RuntimeException $e) {
            @unlink($tmp);

            throw $e;
        }
    }
}
