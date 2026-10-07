<?php

declare(strict_types=1);

namespace Pulsar\Live;

use Pulsar\Api\Api;

/**
 * Represents a file uploaded through a Live component.
 *
 * Immutable value object containing file metadata and the temporary path.
 * The component can then move the file to permanent storage.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class UploadedFile
{
    public function __construct(
        public string $originalName,
        public string $mimeType,
        public int $size,
        public string $temporaryPath,
    ) {}

    /**
     * Get the file extension from the original name.
     */
    public function extension(): string
    {
        $pos = strrpos($this->originalName, '.');

        return $pos !== false ? strtolower(substr($this->originalName, $pos + 1)) : '';
    }

    /**
     * Get a safe filename based on the original name.
     */
    public function safeFilename(): string
    {
        $name = pathinfo($this->originalName, PATHINFO_FILENAME);
        $ext = $this->extension();
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) ?? 'file';

        return $ext !== '' ? "{$safe}.{$ext}" : $safe;
    }

    /**
     * Whether the file exists at its temporary path.
     */
    public function exists(): bool
    {
        return file_exists($this->temporaryPath);
    }
}
