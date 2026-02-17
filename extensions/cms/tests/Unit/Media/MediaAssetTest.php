<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\MediaVisibility;

#[CoversClass(MediaAsset::class)]
final class MediaAssetTest extends TestCase
{
    #[Test]
    public function create_returns_asset_with_defaults(): void
    {
        $asset = MediaAsset::create(
            id: 'media-1',
            uploaderId: 'user-1',
            filename: 'photo.jpg',
            storagePath: 'uploads/2025/01/photo.jpg',
            disk: 'local',
            mimeType: 'image/jpeg',
            fileSize: 1024000,
            fileHash: 'sha256hash',
        );

        self::assertSame('media-1', $asset->id);
        self::assertSame('user-1', $asset->uploaderId);
        self::assertSame('photo.jpg', $asset->filename);
        self::assertSame('uploads/2025/01/photo.jpg', $asset->storagePath);
        self::assertSame('local', $asset->disk);
        self::assertSame('image/jpeg', $asset->mimeType);
        self::assertSame(1024000, $asset->fileSize);
        self::assertSame('sha256hash', $asset->fileHash);
        self::assertNull($asset->width);
        self::assertNull($asset->height);
        self::assertNull($asset->exifData);
        self::assertNull($asset->altTextDefault);
        self::assertNull($asset->tenantId);
        self::assertSame(MediaVisibility::Public, $asset->visibility);
        self::assertSame(DataClassification::Public, $asset->dataClassification);
        self::assertNull($asset->deletedAt);
    }

    #[Test]
    public function create_with_all_optional_params(): void
    {
        $asset = MediaAsset::create(
            id: 'media-2',
            uploaderId: 'user-1',
            filename: 'doc.pdf',
            storagePath: 'docs/doc.pdf',
            disk: 'private',
            mimeType: 'application/pdf',
            fileSize: 2048,
            fileHash: 'hash2',
            width: 800,
            height: 600,
            exifData: ['Make' => 'Canon'],
            altTextDefault: 'A document',
            tenantId: 'tenant-1',
            visibility: MediaVisibility::Private,
            dataClassification: DataClassification::Confidential,
        );

        self::assertSame(800, $asset->width);
        self::assertSame(600, $asset->height);
        self::assertSame(['Make' => 'Canon'], $asset->exifData);
        self::assertSame('A document', $asset->altTextDefault);
        self::assertSame('tenant-1', $asset->tenantId);
        self::assertSame(MediaVisibility::Private, $asset->visibility);
        self::assertSame(DataClassification::Confidential, $asset->dataClassification);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function imageDetectionProvider(): iterable
    {
        yield 'jpeg is image' => ['image/jpeg', true];
        yield 'png is image' => ['image/png', true];
        yield 'webp is image' => ['image/webp', true];
        yield 'svg is image' => ['image/svg+xml', true];
        yield 'pdf is not image' => ['application/pdf', false];
        yield 'text is not image' => ['text/plain', false];
        yield 'video is not image' => ['video/mp4', false];
    }

    #[Test]
    #[DataProvider('imageDetectionProvider')]
    public function isImage_detects_image_mime_types(string $mimeType, bool $expected): void
    {
        $asset = MediaAsset::create(
            id: 'a1',
            uploaderId: 'u1',
            filename: 'file',
            storagePath: 'path',
            disk: 'local',
            mimeType: $mimeType,
            fileSize: 100,
            fileHash: 'hash',
        );

        self::assertSame($expected, $asset->isImage());
    }

    #[Test]
    public function isDeleted_reflects_deletedAt_state(): void
    {
        $asset = MediaAsset::create(
            id: 'a1',
            uploaderId: 'u1',
            filename: 'file',
            storagePath: 'path',
            disk: 'local',
            mimeType: 'text/plain',
            fileSize: 100,
            fileHash: 'hash',
        );

        self::assertFalse($asset->isDeleted());
    }
}
