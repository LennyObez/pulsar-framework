<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Security;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Config\MediaConfig;
use Pulsar\Extension\Cms\Exception\CmsException;

use function in_array;
use function pathinfo;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function substr;

use const PATHINFO_EXTENSION;

/**
 * Orchestrates a 7-step file validation pipeline for media uploads.
 *
 * Steps:
 * 1. Extension check
 * 2. Magic byte detection
 * 3. MIME type consistency
 * 4. Image-specific validation (dimensions, pixel count, embedded PHP)
 * 5-7. Reserved for format-specific validators (SVG, PDF)
 */
#[Api(since: '1.0.0')]
final readonly class FileValidator
{
    /**
     * Extension-to-MIME-type mapping for consistency checks.
     *
     * @var array<string, string>
     */
    private const EXTENSION_MIME_MAP = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
    ];

    public function __construct(
        private MediaConfig $config,
    ) {}

    /**
     * Validate an uploaded file through the full security pipeline.
     *
     * @param string $filePath Path to the temporary uploaded file
     * @param string $originalFilename Original filename from the upload
     * @param string $declaredMimeType Content-Type header from the upload
     * @param int $fileSize File size in bytes
     *
     * @throws CmsException If any validation step fails
     */
    public function validate(
        string $filePath,
        string $originalFilename,
        string $declaredMimeType,
        int $fileSize,
    ): void {
        // Step 0: Size check
        if ($fileSize > $this->config->maxUploadSize) {
            throw CmsException::fileTooLarge($fileSize, $this->config->maxUploadSize);
        }

        // Step 1: Extension check
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $this->validateExtension($extension);

        // Step 2: Magic byte detection
        $detectedMime = $this->detectMimeByMagicBytes($filePath, $extension);

        // Step 3: MIME consistency
        $this->validateMimeConsistency($detectedMime, $declaredMimeType, $extension);

        // Step 4: Image-specific validation
        if (str_starts_with($detectedMime, 'image/') && $detectedMime !== 'image/svg+xml') {
            $this->validateImage($filePath, $detectedMime);
        }
    }

    /**
     * Step 1: Validate the file extension against the allowlist.
     *
     * @throws CmsException If extension is not allowed
     */
    private function validateExtension(string $extension): void
    {
        if (!in_array($extension, $this->config->allowedExtensions, true)) {
            throw CmsException::disallowedExtension($extension);
        }
    }

    /**
     * Step 2: Detect MIME type by inspecting the first 4096 bytes for magic signatures.
     *
     * @throws CmsException If magic bytes do not match any known signature
     */
    private function detectMimeByMagicBytes(string $filePath, string $extension): string
    {
        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            throw CmsException::invalidImageFile();
        }

        $header = fread($handle, 4096);
        fclose($handle);

        if ($header === false || $header === '') {
            throw CmsException::invalidImageFile();
        }

        // JPEG: FF D8 FF
        if (str_starts_with($header, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        // PNG: 89 50 4E 47 0D 0A 1A 0A
        if (str_starts_with($header, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }

        // WebP: RIFF + 4 bytes + WEBP
        if (str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        // AVIF: bytes 4-11 contain "ftypavif"
        if (substr($header, 4, 8) === 'ftypavif') {
            return 'image/avif';
        }

        // GIF: GIF89a or GIF87a
        if (str_starts_with($header, 'GIF89') || str_starts_with($header, 'GIF87')) {
            return 'image/gif';
        }

        // PDF: %PDF-
        if (str_starts_with($header, '%PDF-')) {
            return 'application/pdf';
        }

        // SVG: <?xml or <svg (trimmed)
        $trimmedHeader = ltrim($header);

        if (str_starts_with($trimmedHeader, '<?xml') || str_starts_with($trimmedHeader, '<svg')) {
            return 'image/svg+xml';
        }

        // No known magic bytes matched — use extension mapping as fallback
        $expectedMime = self::EXTENSION_MIME_MAP[$extension] ?? null;

        if ($expectedMime !== null) {
            throw CmsException::magicByteMismatch($expectedMime);
        }

        throw CmsException::magicByteMismatch('unknown');
    }

    /**
     * Step 3: Validate MIME type consistency between detected, declared, and extension-mapped types.
     *
     * @throws CmsException If types are inconsistent
     */
    private function validateMimeConsistency(string $detected, string $declared, string $extension): void
    {
        // Allow MIME types from the configured allowlist only
        if (!in_array($detected, $this->config->allowedMimeTypes, true)) {
            throw CmsException::mimeTypeMismatch($detected, $declared);
        }

        // The extension must map to the detected type
        $extensionMime = self::EXTENSION_MIME_MAP[$extension] ?? null;

        if ($extensionMime !== null && $extensionMime !== $detected) {
            throw CmsException::mimeTypeMismatch($detected, $extensionMime);
        }

        // Declared Content-Type must match detected (normalize jpeg variants)
        $normalizedDeclared = $this->normalizeMime($declared);
        $normalizedDetected = $this->normalizeMime($detected);

        if ($normalizedDeclared !== $normalizedDetected) {
            throw CmsException::mimeTypeMismatch($detected, $declared);
        }
    }

    /**
     * Step 4: Validate image dimensions, pixel count, and scan for embedded PHP.
     *
     * @throws CmsException If image validation fails
     */
    private function validateImage(string $filePath, string $mimeType): void
    {
        $info = @getimagesize($filePath);

        if ($info === false) {
            throw CmsException::invalidImageFile();
        }

        $width = $info[0];
        $height = $info[1];

        // Dimension limits
        if ($width > $this->config->maxImageWidth || $height > $this->config->maxImageHeight) {
            throw CmsException::imageDimensionsExceeded(
                $width,
                $height,
                $this->config->maxImageWidth,
                $this->config->maxImageHeight,
            );
        }

        // Pixel count (decompression bomb protection)
        $pixelCount = $width * $height;

        if ($pixelCount > $this->config->maxPixelCount) {
            throw CmsException::pixelCountExceeded($pixelCount, $this->config->maxPixelCount);
        }

        // JPEG-specific: scan first 64KB for embedded PHP
        if ($mimeType === 'image/jpeg') {
            $this->scanForEmbeddedPhp($filePath);
        }
    }

    /**
     * Scan entire file contents for embedded PHP opening tags.
     *
     * Checks for both `<?php` and short echo `<?=` tags which can be
     * used to inject executable PHP into image files.
     *
     * @throws CmsException If embedded PHP is detected
     */
    private function scanForEmbeddedPhp(string $filePath): void
    {
        $contents = file_get_contents($filePath);

        if ($contents === false) {
            return;
        }

        if (str_contains($contents, '<?php') || str_contains($contents, '<?=')) {
            throw CmsException::embeddedPhpDetected();
        }
    }

    /**
     * Normalize MIME type for comparison (handles jpeg/jpg variants).
     */
    private function normalizeMime(string $mime): string
    {
        return match (strtolower(trim($mime))) {
            'image/jpg' => 'image/jpeg',
            default => strtolower(trim($mime)),
        };
    }
}
