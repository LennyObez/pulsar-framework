<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Media;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\MediaAssetTranslation;
use Pulsar\Extension\Cms\Media\MediaDerivative;
use Pulsar\Extension\Cms\Media\MediaVisibility;

#[CoversClass(MediaAsset::class)]
#[CoversClass(MediaAssetTranslation::class)]
#[CoversClass(MediaDerivative::class)]
#[CoversClass(MediaVisibility::class)]
final class MediaEntitiesTest extends TestCase
{
    // -- MediaVisibility ------------------------------------------------------

    #[Test]
    public function mediaVisibilityValues(): void
    {
        self::assertSame('public', MediaVisibility::Public->value);
        self::assertSame('private', MediaVisibility::Private->value);
    }

    // -- MediaAsset -----------------------------------------------------------

    #[Test]
    public function mediaAssetCreateFactory(): void
    {
        $asset = MediaAsset::create(
            id: 'media-01',
            uploaderId: 'user-01',
            filename: 'hero-image.jpg',
            storagePath: '2025/03/hero-image.jpg',
            disk: 'local',
            mimeType: 'image/jpeg',
            fileSize: 2_500_000,
            fileHash: hash('sha256', 'image-content'),
            width: 1920,
            height: 1080,
            tenantId: 'tenant-01',
        );

        self::assertSame('media-01', $asset->id);
        self::assertSame('tenant-01', $asset->tenantId);
        self::assertSame('user-01', $asset->uploaderId);
        self::assertSame('hero-image.jpg', $asset->filename);
        self::assertSame('image/jpeg', $asset->mimeType);
        self::assertSame(2_500_000, $asset->fileSize);
        self::assertSame(1920, $asset->width);
        self::assertSame(1080, $asset->height);
        self::assertSame(MediaVisibility::Public, $asset->visibility);
        self::assertSame(DataClassification::Public, $asset->dataClassification);
        self::assertNull($asset->deletedAt);
    }

    #[Test]
    public function mediaAssetCreateWithPrivateVisibility(): void
    {
        $asset = MediaAsset::create(
            id: 'media-02',
            uploaderId: 'user-02',
            filename: 'contract.pdf',
            storagePath: '2025/03/contract.pdf',
            disk: 'local',
            mimeType: 'application/pdf',
            fileSize: 500_000,
            fileHash: hash('sha256', 'pdf-content'),
            visibility: MediaVisibility::Private,
            dataClassification: DataClassification::Confidential,
        );

        self::assertSame(MediaVisibility::Private, $asset->visibility);
        self::assertSame(DataClassification::Confidential, $asset->dataClassification);
        self::assertNull($asset->width);
        self::assertNull($asset->height);
    }

    #[Test]
    public function mediaAssetIsImage(): void
    {
        $image = MediaAsset::create(
            id: 'media-03',
            uploaderId: 'user-01',
            filename: 'photo.png',
            storagePath: '2025/03/photo.png',
            disk: 'local',
            mimeType: 'image/png',
            fileSize: 1_000_000,
            fileHash: hash('sha256', 'png-content'),
        );

        $pdf = MediaAsset::create(
            id: 'media-04',
            uploaderId: 'user-01',
            filename: 'doc.pdf',
            storagePath: '2025/03/doc.pdf',
            disk: 'local',
            mimeType: 'application/pdf',
            fileSize: 200_000,
            fileHash: hash('sha256', 'pdf-content'),
        );

        self::assertTrue($image->isImage());
        self::assertFalse($pdf->isImage());
    }

    #[Test]
    public function mediaAssetIsDeleted(): void
    {
        $now = new DateTimeImmutable();

        $active = new MediaAsset(
            id: 'media-05',
            tenantId: null,
            uploaderId: 'user-01',
            filename: 'active.jpg',
            storagePath: 'active.jpg',
            disk: 'local',
            mimeType: 'image/jpeg',
            fileSize: 100,
            fileHash: 'hash',
            width: 100,
            height: 100,
            exifData: null,
            altTextDefault: null,
            visibility: MediaVisibility::Public,
            dataClassification: DataClassification::Public,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );

        $deleted = new MediaAsset(
            id: 'media-06',
            tenantId: null,
            uploaderId: 'user-01',
            filename: 'deleted.jpg',
            storagePath: 'deleted.jpg',
            disk: 'local',
            mimeType: 'image/jpeg',
            fileSize: 100,
            fileHash: 'hash',
            width: 100,
            height: 100,
            exifData: null,
            altTextDefault: null,
            visibility: MediaVisibility::Public,
            dataClassification: DataClassification::Public,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: $now,
        );

        self::assertFalse($active->isDeleted());
        self::assertTrue($deleted->isDeleted());
    }

    // -- MediaAssetTranslation ------------------------------------------------

    #[Test]
    public function mediaAssetTranslationConstructor(): void
    {
        $trans = new MediaAssetTranslation(
            mediaAssetId: 'media-01',
            locale: 'de',
            altText: 'Ein schoenes Bild',
            caption: 'Landschaft bei Sonnenuntergang',
            title: 'Sonnenuntergang',
        );

        self::assertSame('media-01', $trans->mediaAssetId);
        self::assertSame('de', $trans->locale);
        self::assertSame('Ein schoenes Bild', $trans->altText);
        self::assertSame('Landschaft bei Sonnenuntergang', $trans->caption);
        self::assertSame('Sonnenuntergang', $trans->title);
    }

    #[Test]
    public function mediaAssetTranslationNullableFields(): void
    {
        $trans = new MediaAssetTranslation(
            mediaAssetId: 'media-02',
            locale: 'en',
            altText: null,
            caption: null,
            title: null,
        );

        self::assertNull($trans->altText);
        self::assertNull($trans->caption);
        self::assertNull($trans->title);
    }

    // -- MediaDerivative ------------------------------------------------------

    #[Test]
    public function mediaDerivativeConstructor(): void
    {
        $now = new DateTimeImmutable('2025-03-07T10:00:00+00:00');

        $derivative = new MediaDerivative(
            id: 'deriv-01',
            mediaAssetId: 'media-01',
            variant: 'thumbnail',
            format: 'webp',
            storagePath: '2025/03/hero-image-thumb.webp',
            fileSize: 25_000,
            width: 300,
            height: 169,
            fileHash: hash('sha256', 'thumb-content'),
            createdAt: $now,
        );

        self::assertSame('deriv-01', $derivative->id);
        self::assertSame('media-01', $derivative->mediaAssetId);
        self::assertSame('thumbnail', $derivative->variant);
        self::assertSame('webp', $derivative->format);
        self::assertSame(25_000, $derivative->fileSize);
        self::assertSame(300, $derivative->width);
        self::assertSame(169, $derivative->height);
    }
}
