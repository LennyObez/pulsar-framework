<?php

declare(strict_types=1);

namespace Pulsar\Live;

use Attribute;
use Pulsar\Api\Api;

use function in_array;

/**
 * Marks a LiveProp as a file upload field.
 *
 * Enables wire:model binding for file inputs with progress tracking.
 * The file is temporarily stored on the server during the upload,
 * and the property receives an UploadedFile value object.
 *
 * Usage:
 *   #[LiveProp(writable: true)]
 *   #[FileUpload(maxSize: 10_485_760, accept: ['image/png', 'image/jpeg'])]
 *   public ?UploadedFile $avatar = null;
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
#[Api(since: '1.0.0')]
final readonly class FileUpload
{
    /**
     * @param int $maxSize Maximum file size in bytes (default 10MB)
     * @param list<string> $accept Allowed MIME types (empty = any)
     * @param string $disk Storage disk for temporary files
     * @param bool $multiple Allow multiple files
     */
    public function __construct(
        public int $maxSize = 10_485_760,
        public array $accept = [],
        public string $disk = 'local',
        public bool $multiple = false,
    ) {}

    /**
     * Validate a file against this upload's constraints.
     *
     * @return list<string> Validation errors (empty if valid)
     */
    public function validateFile(string $mimeType, int $size, string $originalName): array
    {
        $errors = [];

        if ($size > $this->maxSize) {
            $maxMb = round($this->maxSize / 1_048_576, 1);
            $errors[] = "{$originalName} exceeds the maximum size of {$maxMb}MB.";
        }

        if ($this->accept !== [] && !in_array($mimeType, $this->accept, true)) {
            $allowed = implode(', ', $this->accept);
            $errors[] = "{$originalName} has an unsupported type. Allowed: {$allowed}";
        }

        return $errors;
    }
}
