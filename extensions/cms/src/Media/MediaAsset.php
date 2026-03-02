<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Content\DataClassification;

/**
 * Media asset aggregate root: represents an uploaded file
 * (image, document, or other media) stored in the CMS.
 */
#[Api(since: '1.0.0')]
final readonly class MediaAsset
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $uploaderId UUIDv7 of the user who uploaded
     * @param string $filename Sanitized filename
     * @param string $storagePath Relative path within the storage disk
     * @param string $disk Storage disk name
     * @param string $mimeType Validated MIME type
     * @param int $fileSize File size in bytes
     * @param string $fileHash SHA-256 hash of file contents
     * @param int|null $width Image width in pixels (null for non-images)
     * @param int|null $height Image height in pixels (null for non-images)
     * @param array<string, mixed>|null $exifData Extracted EXIF metadata
     * @param string|null $altTextDefault Default alt text (fallback when no translation)
     * @param MediaVisibility $visibility Access visibility level
     * @param DataClassification $dataClassification Data classification level
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     * @param DateTimeImmutable $updatedAt Auto-managed update timestamp
     * @param DateTimeImmutable|null $deletedAt Soft delete timestamp
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $uploaderId,
        public string $filename,
        public string $storagePath,
        public string $disk,
        public string $mimeType,
        public int $fileSize,
        public string $fileHash,
        public ?int $width,
        public ?int $height,
        public ?array $exifData,
        public ?string $altTextDefault,
        public MediaVisibility $visibility,
        public DataClassification $dataClassification,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
    ) {}

    /**
     * Create a new media asset for a fresh upload.
     */
    /**
     * @param array<string, mixed>|null $exifData
     */
    public static function create(
        string $id,
        string $uploaderId,
        string $filename,
        string $storagePath,
        string $disk,
        string $mimeType,
        int $fileSize,
        string $fileHash,
        ?int $width = null,
        ?int $height = null,
        ?array $exifData = null,
        ?string $altTextDefault = null,
        ?string $tenantId = null,
        MediaVisibility $visibility = MediaVisibility::Public,
        DataClassification $dataClassification = DataClassification::Public,
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            tenantId: $tenantId,
            uploaderId: $uploaderId,
            filename: $filename,
            storagePath: $storagePath,
            disk: $disk,
            mimeType: $mimeType,
            fileSize: $fileSize,
            fileHash: $fileHash,
            width: $width,
            height: $height,
            exifData: $exifData,
            altTextDefault: $altTextDefault,
            visibility: $visibility,
            dataClassification: $dataClassification,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
