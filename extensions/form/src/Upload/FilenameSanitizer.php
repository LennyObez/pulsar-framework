<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Upload;

use Pulsar\Api\Api;
use Random\Engine\Secure;
use Random\Randomizer;

use function chr;
use function ord;
use function pathinfo;
use function preg_replace;
use function sprintf;
use function str_replace;
use function strtolower;
use function trim;

use const PATHINFO_EXTENSION;

/**
 * Sanitizes uploaded file names for secure storage.
 *
 * Strips path traversal sequences, control characters, and null bytes.
 * Generates UUID-based storage filenames to prevent enumeration.
 * @api
 */
#[Api(since: '1.0.0')]
final class FilenameSanitizer
{
    /**
     * Sanitize an original filename by removing dangerous characters.
     */
    public function sanitize(string $filename): string
    {
        // Remove null bytes
        $filename = str_replace("\0", '', $filename);

        // Remove path traversal
        $filename = str_replace(['../', '..\\', '/', '\\'], '', $filename);

        // Remove control characters
        $filename = preg_replace('/[\x00-\x1F\x7F]/', '', $filename) ?? $filename;

        // Trim whitespace and dots from edges
        return trim($filename, " \t\n\r\0\x0B.");
    }

    /**
     * Generate a UUID-based storage filename preserving the original extension.
     */
    public function generateStorageName(string $originalFilename): string
    {
        $extension = strtolower(pathinfo($this->sanitize($originalFilename), PATHINFO_EXTENSION));
        $uuid = $this->generateUuid();

        if ($extension !== '') {
            return $uuid . '.' . $extension;
        }

        return $uuid;
    }

    private function generateUuid(): string
    {
        $randomizer = new Randomizer(new Secure());
        $bytes = $randomizer->getBytes(16);

        // Set version 4 (random) and variant bits
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        );
    }
}
