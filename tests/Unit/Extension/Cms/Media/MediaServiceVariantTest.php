<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Media;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Cms\Config\MediaConfig;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Media\ImageProcessorInterface;
use Pulsar\Extension\Cms\Media\ImageVariant;
use Pulsar\Extension\Cms\Media\ImageVariantGenerator;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\MediaDerivative;
use Pulsar\Extension\Cms\Media\MediaDiskInterface;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaService;
use Pulsar\Extension\Cms\Media\MediaVisibility;
use Pulsar\Extension\Cms\Media\Security\FilenameSanitizer;
use Pulsar\Extension\Cms\Media\Security\FileValidator;
use Pulsar\Extension\Cms\Media\Security\PdfValidator;
use Pulsar\Extension\Cms\Media\Security\SvgSanitizer;

#[CoversClass(MediaService::class)]
final class MediaServiceVariantTest extends TestCase
{
    /** @var MediaDiskInterface&Stub */
    private MediaDiskInterface&Stub $disk;

    /** @var ImageProcessorInterface&Stub */
    private ImageProcessorInterface&Stub $imageProcessor;

    /** @var MediaRepositoryInterface&Stub */
    private MediaRepositoryInterface&Stub $repository;

    private MediaService $service;

    protected function setUp(): void
    {
        $this->disk = $this->createStub(MediaDiskInterface::class);
        $this->imageProcessor = $this->createStub(ImageProcessorInterface::class);
        $this->repository = $this->createStub(MediaRepositoryInterface::class);

        // ImageVariantGenerator is final — construct a real instance with stubbed deps
        $variantGenerator = new ImageVariantGenerator(
            $this->imageProcessor,
            $this->disk,
        );

        $config = new MediaConfig();

        $this->service = new MediaService(
            disk: $this->disk,
            imageProcessor: $this->imageProcessor,
            fileValidator: new FileValidator($config),
            svgSanitizer: new SvgSanitizer(),
            repository: $this->repository,
            config: $config,
            auditLogger: null,
            filenameSanitizer: new FilenameSanitizer(),
            pdfValidator: new PdfValidator(),
            logger: new NullLogger(),
            variantGenerator: $variantGenerator,
        );
    }

    #[Test]
    public function getVariantsReturnsImageVariantsFromDerivatives(): void
    {
        $asset = $this->createAsset('asset-1', 'image/jpeg');

        $this->repository->method('findById')->willReturn($asset);
        $this->repository->method('findDerivatives')->willReturn([
            new MediaDerivative(
                id: 'deriv-1',
                mediaAssetId: 'asset-1',
                variant: 'thumb_480',
                format: 'webp',
                storagePath: 'tenant/2026/02/ab/derivatives/photo_thumb_480.webp',
                fileSize: 4096,
                width: 480,
                height: 360,
                fileHash: 'abc123',
                createdAt: new DateTimeImmutable(),
            ),
            new MediaDerivative(
                id: 'deriv-2',
                mediaAssetId: 'asset-1',
                variant: 'medium_960',
                format: 'webp',
                storagePath: 'tenant/2026/02/ab/derivatives/photo_medium_960.webp',
                fileSize: 16384,
                width: 960,
                height: 720,
                fileHash: 'def456',
                createdAt: new DateTimeImmutable(),
            ),
        ]);

        $variants = $this->service->getVariants('asset-1');

        self::assertCount(2, $variants);
        self::assertContainsOnlyInstancesOf(ImageVariant::class, $variants);

        self::assertSame(480, $variants[0]->width);
        self::assertSame(360, $variants[0]->height);
        self::assertSame('webp', $variants[0]->format);
        self::assertSame(4096, $variants[0]->sizeBytes);

        self::assertSame(960, $variants[1]->width);
        self::assertSame('webp', $variants[1]->format);
    }

    #[Test]
    public function getVariantsReturnsEmptyWhenNoDerivatives(): void
    {
        $asset = $this->createAsset('asset-2', 'image/jpeg');

        $this->repository->method('findById')->willReturn($asset);
        $this->repository->method('findDerivatives')->willReturn([]);

        $variants = $this->service->getVariants('asset-2');

        self::assertCount(0, $variants);
    }

    #[Test]
    public function getVariantsThrowsForNonexistentAsset(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $this->expectException(CmsException::class);
        $this->service->getVariants('nonexistent');
    }

    #[Test]
    public function getVariantsMapsDerivativeFieldsCorrectly(): void
    {
        $asset = $this->createAsset('asset-3', 'image/png');

        $this->repository->method('findById')->willReturn($asset);
        $this->repository->method('findDerivatives')->willReturn([
            new MediaDerivative(
                id: 'deriv-x',
                mediaAssetId: 'asset-3',
                variant: 'large_1920',
                format: 'avif',
                storagePath: 'tenant/2026/02/cd/derivatives/photo_large_1920.avif',
                fileSize: 65536,
                width: 1920,
                height: 1440,
                fileHash: 'ghi789',
                createdAt: new DateTimeImmutable(),
            ),
        ]);

        $variants = $this->service->getVariants('asset-3');

        self::assertCount(1, $variants);
        self::assertSame('tenant/2026/02/cd/derivatives/photo_large_1920.avif', $variants[0]->path);
        self::assertSame(1920, $variants[0]->width);
        self::assertSame(1440, $variants[0]->height);
        self::assertSame('avif', $variants[0]->format);
        self::assertSame(65536, $variants[0]->sizeBytes);
    }

    private function createAsset(string $id, string $mimeType): MediaAsset
    {
        return MediaAsset::create(
            id: $id,
            uploaderId: 'user-1',
            filename: 'photo.jpg',
            storagePath: 'tenant/2026/02/ab/photo.jpg',
            disk: 'local',
            mimeType: $mimeType,
            fileSize: 102400,
            fileHash: bin2hex(random_bytes(16)),
            width: 2000,
            height: 1500,
            tenantId: null,
            visibility: MediaVisibility::Public,
        );
    }
}
