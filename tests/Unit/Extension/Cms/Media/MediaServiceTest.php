<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Media;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Config\MediaConfig;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Media\ImageProcessorInterface;
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
final class MediaServiceTest extends TestCase
{
    private MediaDiskInterface&Stub $disk;
    private ImageProcessorInterface&Stub $imageProcessor;
    private FileValidator $fileValidator;
    private SvgSanitizer $svgSanitizer;
    private MediaRepositoryInterface&Stub $repository;
    private MediaConfig $config;
    private AuditLoggerInterface&Stub $auditLogger;
    private FilenameSanitizer $filenameSanitizer;
    private PdfValidator $pdfValidator;
    private ImageVariantGenerator $variantGenerator;

    protected function setUp(): void
    {
        $this->disk = $this->createStub(MediaDiskInterface::class);
        $this->imageProcessor = $this->createStub(ImageProcessorInterface::class);
        $this->config = new MediaConfig();
        $this->fileValidator = new FileValidator($this->config);
        $this->svgSanitizer = new SvgSanitizer();
        $this->repository = $this->createStub(MediaRepositoryInterface::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);
        $this->filenameSanitizer = new FilenameSanitizer();
        $this->pdfValidator = new PdfValidator();
        $this->variantGenerator = new ImageVariantGenerator($this->imageProcessor, $this->disk);
    }

    private function createService(): MediaService
    {
        return new MediaService(
            disk: $this->disk,
            imageProcessor: $this->imageProcessor,
            fileValidator: $this->fileValidator,
            svgSanitizer: $this->svgSanitizer,
            repository: $this->repository,
            config: $this->config,
            auditLogger: $this->auditLogger,
            filenameSanitizer: $this->filenameSanitizer,
            pdfValidator: $this->pdfValidator,
            logger: new NullLogger(),
            variantGenerator: $this->variantGenerator,
        );
    }

    private function createTestAsset(
        string $id = 'asset-1',
        string $mimeType = 'image/jpeg',
        string $storagePath = 'default/2026/03/ab/photo.jpg',
        ?int $width = 1920,
        ?int $height = 1080,
    ): MediaAsset {
        return new MediaAsset(
            id: $id,
            tenantId: null,
            uploaderId: 'user-1',
            filename: 'photo.jpg',
            storagePath: $storagePath,
            disk: 'local',
            mimeType: $mimeType,
            fileSize: 102400,
            fileHash: 'abc123',
            width: $width,
            height: $height,
            exifData: null,
            altTextDefault: null,
            visibility: MediaVisibility::Public,
            dataClassification: DataClassification::Public,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            deletedAt: null,
        );
    }

    // --- getPublicUrl ---

    #[Test]
    public function getPublicUrlReturnsOriginalUrlWhenNoVariant(): void
    {
        $asset = $this->createTestAsset();
        $this->repository->method('findById')->willReturn($asset);
        $this->disk->method('url')->willReturn('https://cdn.example.com/default/2026/03/ab/photo.jpg');

        $service = $this->createService();
        $url = $service->getPublicUrl('asset-1');

        self::assertSame('https://cdn.example.com/default/2026/03/ab/photo.jpg', $url);
    }

    #[Test]
    public function getPublicUrlThrowsForMissingAsset(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $service = $this->createService();

        $this->expectException(CmsException::class);
        $service->getPublicUrl('nonexistent');
    }

    #[Test]
    public function getPublicUrlReturnsDerivativeUrlWhenVariantMatches(): void
    {
        $asset = $this->createTestAsset();
        $this->repository->method('findById')->willReturn($asset);

        $derivative = new MediaDerivative(
            id: 'deriv-1',
            mediaAssetId: 'asset-1',
            variant: 'thumb_480',
            format: 'webp',
            storagePath: 'default/2026/03/ab/derivatives/photo_thumb_480.webp',
            fileSize: 12000,
            width: 480,
            height: 270,
            fileHash: 'def456',
            createdAt: new DateTimeImmutable(),
        );
        $this->repository->method('findDerivatives')->willReturn([$derivative]);

        $this->disk->method('url')->willReturnCallback(
            fn(string $path): string => 'https://cdn.example.com/' . $path,
        );

        $service = $this->createService();
        $url = $service->getPublicUrl('asset-1', 'thumb_480');

        self::assertSame(
            'https://cdn.example.com/default/2026/03/ab/derivatives/photo_thumb_480.webp',
            $url,
        );
    }

    #[Test]
    public function getPublicUrlReturnsOriginalWhenVariantNotFound(): void
    {
        $asset = $this->createTestAsset();
        $this->repository->method('findById')->willReturn($asset);
        $this->repository->method('findDerivatives')->willReturn([]);

        $this->disk->method('url')->willReturnCallback(
            fn(string $path): string => 'https://cdn.example.com/' . $path,
        );

        $service = $this->createService();
        $url = $service->getPublicUrl('asset-1', 'nonexistent_variant');

        self::assertSame(
            'https://cdn.example.com/default/2026/03/ab/photo.jpg',
            $url,
        );
    }

    #[Test]
    public function getPublicUrlMatchesFormatWhenProvided(): void
    {
        $asset = $this->createTestAsset();
        $this->repository->method('findById')->willReturn($asset);

        $webp = new MediaDerivative(
            id: 'deriv-1',
            mediaAssetId: 'asset-1',
            variant: 'medium_960',
            format: 'webp',
            storagePath: 'path/photo_medium_960.webp',
            fileSize: 50000,
            width: 960,
            height: 540,
            fileHash: 'hash1',
            createdAt: new DateTimeImmutable(),
        );

        $avif = new MediaDerivative(
            id: 'deriv-2',
            mediaAssetId: 'asset-1',
            variant: 'medium_960',
            format: 'avif',
            storagePath: 'path/photo_medium_960.avif',
            fileSize: 40000,
            width: 960,
            height: 540,
            fileHash: 'hash2',
            createdAt: new DateTimeImmutable(),
        );

        $this->repository->method('findDerivatives')->willReturn([$webp, $avif]);
        $this->disk->method('url')->willReturnCallback(
            fn(string $path): string => 'https://cdn.example.com/' . $path,
        );

        $service = $this->createService();
        $url = $service->getPublicUrl('asset-1', 'medium_960', 'avif');

        self::assertSame('https://cdn.example.com/path/photo_medium_960.avif', $url);
    }

    // --- getVariants ---

    #[Test]
    public function getVariantsThrowsForMissingAsset(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $service = $this->createService();

        $this->expectException(CmsException::class);
        $service->getVariants('nonexistent');
    }

    #[Test]
    public function getVariantsReturnsImageVariantsFromDerivatives(): void
    {
        $asset = $this->createTestAsset();
        $this->repository->method('findById')->willReturn($asset);

        $derivative = new MediaDerivative(
            id: 'deriv-1',
            mediaAssetId: 'asset-1',
            variant: 'thumb_480',
            format: 'webp',
            storagePath: 'path/photo_thumb.webp',
            fileSize: 12000,
            width: 480,
            height: 270,
            fileHash: 'hash1',
            createdAt: new DateTimeImmutable(),
        );
        $this->repository->method('findDerivatives')->willReturn([$derivative]);

        $service = $this->createService();
        $variants = $service->getVariants('asset-1');

        self::assertCount(1, $variants);
        self::assertSame(480, $variants[0]->width);
        self::assertSame(270, $variants[0]->height);
        self::assertSame('webp', $variants[0]->format);
        self::assertSame(12000, $variants[0]->sizeBytes);
    }

    #[Test]
    public function getVariantsReturnsEmptyArrayWhenNoDerivatives(): void
    {
        $asset = $this->createTestAsset();
        $this->repository->method('findById')->willReturn($asset);
        $this->repository->method('findDerivatives')->willReturn([]);

        $service = $this->createService();
        $variants = $service->getVariants('asset-1');

        self::assertSame([], $variants);
    }

    // --- sanitizeSvg ---

    #[Test]
    public function sanitizeSvgStripsScriptTags(): void
    {
        $service = $this->createService();

        $result = $service->sanitizeSvg('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="100" height="100"/></svg>');

        self::assertStringNotContainsString('<script>', $result);
        self::assertStringNotContainsString('alert', $result);
    }

    // --- delete ---

    #[Test]
    public function deleteThrowsForMissingAsset(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $service = $this->createService();

        $this->expectException(CmsException::class);
        $service->delete('nonexistent', 'cleanup');
    }

    #[Test]
    public function deleteRemovesDerivativesAndOriginalFromDisk(): void
    {
        $asset = $this->createTestAsset();
        $this->repository->method('findById')->willReturn($asset);

        $derivative = new MediaDerivative(
            id: 'deriv-1',
            mediaAssetId: 'asset-1',
            variant: 'thumb_480',
            format: 'webp',
            storagePath: 'derivatives/photo_thumb.webp',
            fileSize: 12000,
            width: 480,
            height: 270,
            fileHash: 'hash1',
            createdAt: new DateTimeImmutable(),
        );
        $this->repository->method('findDerivatives')->willReturn([$derivative]);

        $deletedPaths = [];
        $disk = $this->createMock(MediaDiskInterface::class);
        $disk->expects(self::exactly(2))
            ->method('delete')
            ->willReturnCallback(function (string $path) use (&$deletedPaths): void {
                $deletedPaths[] = $path;
            });

        $repo = $this->createMock(MediaRepositoryInterface::class);
        $repo->method('findById')->willReturn($asset);
        $repo->method('findDerivatives')->willReturn([$derivative]);
        $repo->expects(self::once())->method('delete')->with($asset);

        $service = new MediaService(
            disk: $disk,
            imageProcessor: $this->imageProcessor,
            fileValidator: $this->fileValidator,
            svgSanitizer: $this->svgSanitizer,
            repository: $repo,
            config: $this->config,
            auditLogger: $this->auditLogger,
            filenameSanitizer: $this->filenameSanitizer,
            pdfValidator: $this->pdfValidator,
            logger: new NullLogger(),
            variantGenerator: $this->variantGenerator,
        );

        $service->delete('asset-1', 'requested by user');

        self::assertSame([
            'derivatives/photo_thumb.webp',
            'default/2026/03/ab/photo.jpg',
        ], $deletedPaths);
    }

    #[Test]
    public function deleteLogsAuditEvent(): void
    {
        $asset = $this->createTestAsset();
        $this->repository->method('findById')->willReturn($asset);
        $this->repository->method('findDerivatives')->willReturn([]);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('log');

        $service = new MediaService(
            disk: $this->disk,
            imageProcessor: $this->imageProcessor,
            fileValidator: $this->fileValidator,
            svgSanitizer: $this->svgSanitizer,
            repository: $this->repository,
            config: $this->config,
            auditLogger: $auditLogger,
            filenameSanitizer: $this->filenameSanitizer,
            pdfValidator: $this->pdfValidator,
            logger: new NullLogger(),
            variantGenerator: $this->variantGenerator,
        );

        $service->delete('asset-1', 'policy violation');
    }

    // --- generateDerivatives ---

    #[Test]
    public function generateDerivativesThrowsForMissingAsset(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $service = $this->createService();

        $this->expectException(CmsException::class);
        $service->generateDerivatives('nonexistent');
    }

    #[Test]
    public function generateDerivativesSkipsNonImageAssets(): void
    {
        $pdfAsset = $this->createTestAsset(mimeType: 'application/pdf', width: null, height: null);
        $this->repository->method('findById')->willReturn($pdfAsset);

        $disk = $this->createMock(MediaDiskInterface::class);
        $disk->expects(self::never())->method('read');

        $service = new MediaService(
            disk: $disk,
            imageProcessor: $this->imageProcessor,
            fileValidator: $this->fileValidator,
            svgSanitizer: $this->svgSanitizer,
            repository: $this->repository,
            config: $this->config,
            auditLogger: $this->auditLogger,
            filenameSanitizer: $this->filenameSanitizer,
            pdfValidator: $this->pdfValidator,
            logger: new NullLogger(),
            variantGenerator: $this->variantGenerator,
        );

        // Should not throw, just return early
        $service->generateDerivatives('asset-1');
    }

    #[Test]
    public function generateDerivativesSkipsSvgAssets(): void
    {
        $svgAsset = $this->createTestAsset(mimeType: 'image/svg+xml');
        $this->repository->method('findById')->willReturn($svgAsset);

        $disk = $this->createMock(MediaDiskInterface::class);
        $disk->expects(self::never())->method('read');

        $service = new MediaService(
            disk: $disk,
            imageProcessor: $this->imageProcessor,
            fileValidator: $this->fileValidator,
            svgSanitizer: $this->svgSanitizer,
            repository: $this->repository,
            config: $this->config,
            auditLogger: $this->auditLogger,
            filenameSanitizer: $this->filenameSanitizer,
            pdfValidator: $this->pdfValidator,
            logger: new NullLogger(),
            variantGenerator: $this->variantGenerator,
        );

        $service->generateDerivatives('asset-1');
    }
}
