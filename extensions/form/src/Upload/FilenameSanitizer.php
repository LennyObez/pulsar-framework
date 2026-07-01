<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Upload;

use Pulsar\Api\Api;
use Random\Engine\Secure;
use Random\Randomizer;

use function chr;
use function in_array;
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
 * Generates UUID-based storage filenames to prevent enumeration, and derives
 * the stored extension from the *detected* content type rather than the
 * client-supplied name — never writing a server-executable extension — so a
 * content/extension polyglot cannot be stored as `.php` and executed.
 * @api
 */
#[Api(since: '1.0.0')]
final class FilenameSanitizer
{
    /**
     * Canonical extension per detected MIME type (the trustworthy source for
     * the stored extension; the magic-byte sniffer determines the MIME).
     *
     * @var array<string, string>
     */
    private const array MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/x-icon' => 'ico',
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
        'application/msword' => 'doc',
        'application/gzip' => 'gz',
        'application/x-bzip2' => 'bz2',
        'application/x-xz' => 'xz',
        'application/x-rar-compressed' => 'rar',
        'audio/mpeg' => 'mp3',
        'audio/flac' => 'flac',
        'video/webm' => 'webm',
        'application/xml' => 'xml',
        'application/json' => 'json',
        'text/plain' => 'txt',
    ];

    /**
     * Extensions that must never be written to disk, regardless of content:
     * server-executable or interpreter handlers that could yield RCE if the
     * upload directory is web-served.
     *
     * @var list<string>
     */
    private const array DANGEROUS_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phpt', 'phar',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'jspx',
        'exe', 'bat', 'cmd', 'com', 'htaccess', 'shtml',
    ];

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
     * Generate a UUID-based storage filename with a safe extension.
     *
     * When the detected MIME type is known, its canonical extension is used so
     * the stored extension always matches the real content. Otherwise the
     * client extension is used only if it is not a server-executable one.
     */
    public function generateStorageName(string $originalFilename, ?string $detectedMime = null): string
    {
        $uuid = $this->generateUuid();
        $extension = $this->safeExtension($originalFilename, $detectedMime);

        if ($extension !== '') {
            return $uuid . '.' . $extension;
        }

        return $uuid;
    }

    /**
     * Resolve a safe storage extension: prefer the detected content type's
     * canonical extension; fall back to the sanitized client extension unless
     * it is a server-executable one, in which case no extension is written.
     */
    private function safeExtension(string $originalFilename, ?string $detectedMime): string
    {
        if ($detectedMime !== null && isset(self::MIME_EXTENSIONS[$detectedMime])) {
            return self::MIME_EXTENSIONS[$detectedMime];
        }

        $extension = strtolower(pathinfo($this->sanitize($originalFilename), PATHINFO_EXTENSION));

        if ($extension === '' || in_array($extension, self::DANGEROUS_EXTENSIONS, true)) {
            return '';
        }

        return $extension;
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
