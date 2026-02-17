<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Upload;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Extension\Form\Config\UploadConfig;
use Pulsar\Extension\Form\Contract\AntivirusPort;
use Pulsar\Extension\Form\Exception\UploadException;

use function file_exists;
use function filesize;
use function in_array;
use function is_dir;
use function mkdir;
use function rename;

/**
 * Processes uploaded files with strict security controls.
 *
 * Validates MIME type by magic bytes, enforces size limits,
 * sanitizes filenames, and integrates with antivirus scanning.
 */
#[Api(since: '1.0.0')]
final readonly class UploadedFileHandler
{
    public function __construct(
        private UploadConfig $config,
        private MimeSniffer $mimeSniffer,
        private FilenameSanitizer $sanitizer,
        private ?AntivirusPort $antivirusPort = null,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Process an uploaded file: validate, scan, and move to storage.
     *
     * @param string $tmpPath Temporary file path from PHP upload
     * @param string $originalName Original filename from the client
     * @param string $fieldName Form field name (for error messages)
     * @param list<string> $allowedMimeTypes Allowed MIME types for this field
     * @param int|null $fieldMaxSize Per-field max size override
     *
     * @return UploadResult The result with storage path and metadata
     *
     * @throws UploadException On validation or processing failure
     */
    public function handle(
        string $tmpPath,
        string $originalName,
        string $fieldName,
        array $allowedMimeTypes = [],
        ?int $fieldMaxSize = null,
    ): UploadResult {
        // Check file exists
        if (!file_exists($tmpPath)) {
            throw UploadException::moveFailed($fieldName, $tmpPath);
        }

        // Enforce size limits
        $size = filesize($tmpPath);

        if ($size === false) {
            throw UploadException::moveFailed($fieldName, $tmpPath);
        }

        $maxSize = $fieldMaxSize ?? $this->config->maxSize;

        if ($size > $maxSize) {
            throw UploadException::fileTooLarge($fieldName, $maxSize);
        }

        // MIME sniffing (magic bytes, not Content-Type)
        $detectedMime = $this->mimeSniffer->detect($tmpPath);

        if ($allowedMimeTypes !== [] && !in_array($detectedMime, $allowedMimeTypes, true)) {
            throw UploadException::invalidMimeType(
                $fieldName,
                $detectedMime,
                implode(', ', $allowedMimeTypes),
            );
        }

        // Antivirus scanning
        if ($this->antivirusPort !== null) {
            $scanResult = $this->antivirusPort->scan($tmpPath);

            if (!$scanResult->clean) {
                throw UploadException::antivirusScanFailed($fieldName);
            }
        } elseif ($this->logger !== null) {
            $this->logger->warning('No antivirus scanner configured for file upload', [
                'field' => $fieldName,
                'original_name' => $originalName,
            ]);
        }

        // Generate safe storage filename
        $sanitizedOriginal = $this->sanitizer->sanitize($originalName);
        $storageName = $this->sanitizer->generateStorageName($originalName);
        $storageDir = $this->config->directory;

        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0o750, true);
        }

        $storagePath = $storageDir . '/' . $storageName;

        // Move file to storage
        if (!rename($tmpPath, $storagePath)) {
            throw UploadException::moveFailed($fieldName, $storagePath);
        }

        return new UploadResult(
            storagePath: $storagePath,
            storageName: $storageName,
            originalName: $sanitizedOriginal,
            mimeType: $detectedMime,
            size: $size,
        );
    }
}
