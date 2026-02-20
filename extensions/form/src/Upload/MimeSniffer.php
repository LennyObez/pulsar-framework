<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Upload;

use Pulsar\Api\Api;

use function file_get_contents;
use function ord;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Detects file MIME type by inspecting magic bytes.
 *
 * Does NOT trust the Content-Type header from the client.
 * Validates actual file content against known magic byte signatures.
 */
#[Api(since: '1.0.0')]
final class MimeSniffer
{
    /**
     * Known magic byte signatures mapped to MIME types.
     *
     * @var array<string, string>
     */
    private const array SIGNATURES = [
        // Images
        "\xFF\xD8\xFF" => 'image/jpeg',
        "\x89PNG\r\n\x1A\n" => 'image/png',
        'GIF87a' => 'image/gif',
        'GIF89a' => 'image/gif',
        'RIFF' => 'image/webp', // Needs additional check for WEBP
        "\x00\x00\x01\x00" => 'image/x-icon',
        "\x00\x00\x02\x00" => 'image/x-icon',

        // Documents
        '%PDF' => 'application/pdf',
        "PK\x03\x04" => 'application/zip', // Also docx, xlsx, pptx
        "\xD0\xCF\x11\xE0" => 'application/msword',

        // Archives
        "\x1F\x8B" => 'application/gzip',
        'BZh' => 'application/x-bzip2',
        "\xFD7zXZ\x00" => 'application/x-xz',
        "Rar!\x1A\x07" => 'application/x-rar-compressed',

        // Audio/Video
        "\x49\x44\x33" => 'audio/mpeg', // ID3 tag
        'fLaC' => 'audio/flac',
        "\x1A\x45\xDF\xA3" => 'video/webm',

        // Text (must be last; fallback)
        '<?xml' => 'application/xml',
    ];

    /**
     * Detect the MIME type of a file by reading its magic bytes.
     *
     * @return string The detected MIME type, or 'application/octet-stream' if unknown
     */
    public function detect(string $filePath): string
    {
        // Validate the file exists before reading. Returning the default
        // MIME type for missing files is the documented contract; validating
        // up-front avoids the need for `@` error suppression on the read.
        if (!is_file($filePath) || !is_readable($filePath)) {
            return 'application/octet-stream';
        }

        $header = file_get_contents($filePath, false, null, 0, 16);

        if ($header === false) {
            return 'application/octet-stream';
        }

        foreach (self::SIGNATURES as $signature => $mimeType) {
            if (str_starts_with($header, $signature)) {
                // Special check for RIFF/WEBP
                if ($signature === 'RIFF' && !str_contains(substr($header, 8, 4), 'WEBP')) {
                    continue;
                }

                return $mimeType;
            }
        }

        // Check if it looks like plain text (no control chars except \n, \r, \t)
        if ($this->isPlainText($header)) {
            return 'text/plain';
        }

        return 'application/octet-stream';
    }

    /**
     * Check if the given content appears to be plain text.
     */
    private function isPlainText(string $content): bool
    {
        for ($i = 0; $i < strlen($content); $i++) {
            $byte = ord($content[$i]);

            if ($byte < 32 && $byte !== 9 && $byte !== 10 && $byte !== 13) {
                return false;
            }
        }

        return true;
    }
}
