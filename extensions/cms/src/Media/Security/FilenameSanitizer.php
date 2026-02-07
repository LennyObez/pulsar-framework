<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Security;

use Pulsar\Api\Api;

use function basename;
use function preg_replace;
use function strtolower;
use function substr;

/**
 * Sanitizes uploaded filenames to prevent path traversal, special character
 * injection, and other filename-based attacks. Prepends a hash prefix
 * for uniqueness and cache-busting.
 */
#[Api(since: '1.0.0')]
final readonly class FilenameSanitizer
{
    /** Maximum length of the sanitized filename (excluding hash prefix). */
    private const MAX_FILENAME_LENGTH = 200;

    /**
     * Sanitize a filename for safe storage.
     *
     * @param string $filename Original filename from the upload
     * @param string $fileHash SHA-256 hash of the file contents
     *
     * @return string Sanitized filename with hash prefix
     */
    public function sanitize(string $filename, string $fileHash): string
    {
        // Strip path info — basename only
        $sanitized = basename($filename);

        // Lowercase
        $sanitized = strtolower($sanitized);

        // Replace disallowed characters with hyphens
        $sanitized = (string) preg_replace('/[^a-z0-9._-]/', '-', $sanitized);

        // Collapse consecutive hyphens
        $sanitized = (string) preg_replace('/-{2,}/', '-', $sanitized);

        // Trim leading/trailing hyphens
        $sanitized = trim($sanitized, '-');

        // Truncate to max length
        if (mb_strlen($sanitized) > self::MAX_FILENAME_LENGTH) {
            $sanitized = substr($sanitized, 0, self::MAX_FILENAME_LENGTH);
        }

        // Prepend hash prefix for uniqueness
        return substr($fileHash, 0, 16) . '_' . $sanitized;
    }
}
