<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Config\MediaConfig;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Media\ImageProcessorInterface;
use Pulsar\Extension\Cms\Media\ImageVariantGenerator;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\MediaAssetTranslation;
use Pulsar\Extension\Cms\Media\MediaDerivative;
use Pulsar\Extension\Cms\Media\MediaDiskInterface;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaService;
use Pulsar\Extension\Cms\Media\MediaVisibility;
use Pulsar\Extension\Cms\Media\Security\FilenameSanitizer;
use Pulsar\Extension\Cms\Media\Security\FileValidator;
use Pulsar\Extension\Cms\Media\Security\PdfValidator;
use Pulsar\Extension\Cms\Media\Security\SvgSanitizer;
use Pulsar\Http\Message\Stream;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Stringable;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function file_put_contents;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(MediaService::class)]
#[CoversClass(MediaAsset::class)]
#[CoversClass(FileValidator::class)]
#[CoversClass(SvgSanitizer::class)]
#[CoversClass(FilenameSanitizer::class)]
#[CoversClass(PdfValidator::class)]
final class MediaUploadIntegrationTest extends TestCase
{
    private InMemoryMediaDisk $disk;
    private InMemoryMediaRepository $repository;
    private StubImageProcessor $imageProcessor;
    private MediaUploadStubAuditLogger $auditLogger;
    private MediaService $service;
    private MediaConfig $config;

    protected function setUp(): void
    {
        $this->disk = new InMemoryMediaDisk();
        $this->repository = new InMemoryMediaRepository();
        $this->imageProcessor = new StubImageProcessor();
        $this->auditLogger = new MediaUploadStubAuditLogger();
        $this->config = new MediaConfig(
            preserveExif: true,
            avifEnabled: false,
        );

        $this->service = new MediaService(
            disk: $this->disk,
            imageProcessor: $this->imageProcessor,
            fileValidator: new FileValidator($this->config),
            svgSanitizer: new SvgSanitizer(),
            repository: $this->repository,
            config: $this->config,
            auditLogger: $this->auditLogger,
            filenameSanitizer: new FilenameSanitizer(),
            pdfValidator: new PdfValidator(),
            logger: new NullTestLogger(),
            variantGenerator: new ImageVariantGenerator($this->imageProcessor, $this->disk),
        );
    }

    #[Test]
    public function test_upload_valid_jpeg_stores_asset_and_creates_record(): void
    {
        $jpegContent = $this->createMinimalJpeg();
        $file = new StubUploadedFile($jpegContent, 'photo.jpg', 'image/jpeg');

        $asset = $this->service->upload($file, 'uploader-001', null, MediaVisibility::Public);

        self::assertNotEmpty($asset->id);
        self::assertSame('uploader-001', $asset->uploaderId);
        self::assertSame('image/jpeg', $asset->mimeType);
        self::assertSame(strlen($jpegContent), $asset->fileSize);
        self::assertNotEmpty($asset->fileHash);
        self::assertSame(MediaVisibility::Public, $asset->visibility);

        // Asset must be stored on disk
        self::assertTrue($this->disk->exists($asset->storagePath));

        // Asset must be persisted in repository
        $found = $this->repository->findById($asset->id);
        self::assertNotNull($found);
        self::assertSame($asset->id, $found->id);

        // Audit log must be recorded
        $entries = $this->auditLogger->getEntries();
        self::assertCount(1, $entries);
        self::assertSame('cms.media.upload', $entries[0]['action']);
    }

    #[Test]
    public function test_upload_valid_jpeg_generates_derivatives(): void
    {
        $jpegContent = $this->createMinimalJpeg();
        $file = new StubUploadedFile($jpegContent, 'photo.jpg', 'image/jpeg');

        $asset = $this->service->upload($file, 'uploader-001', null, MediaVisibility::Public);
        $this->service->generateDerivatives($asset->id);

        $derivatives = $this->repository->findDerivatives($asset->id);

        // With avifEnabled=false, only webp format; 4 variants (thumb_480, medium_960, large_1920, blur_20)
        // But source image is small (1x1), so only blur_20 variant should match (width < target skips)
        // StubImageProcessor returns files that getimagesize can parse, so all 4 variants proceed
        // Actually: asset->width is 1, so targetWidth > 1 means skip. Only blur_20 is skipped (target=20, 1 < 20 → skip)
        // Wait: the check is `$asset->width < $targetWidth` → skip. So width=1 < 480 → skip all except none.
        // Actually width 1 < 20 too, so ALL variants are skipped. Let's verify 0 derivatives.
        self::assertCount(0, $derivatives);
    }

    #[Test]
    public function test_upload_large_jpeg_generates_all_derivative_variants(): void
    {
        // Create a JPEG that reports as 2000x2000 via StubImageProcessor
        $jpegContent = $this->createMinimalJpeg();
        $file = new StubUploadedFile($jpegContent, 'banner.jpg', 'image/jpeg');

        // Override the image processor to report larger dimensions
        $this->imageProcessor->setForcedDimensions(2000, 2000);

        // We need a service with a config where we can control dimensions
        // Instead, create the asset directly with larger dimensions via a custom upload
        // Actually: the real getimagesize reads the temp file. Our minimal JPEG is 1x1.
        // So derivatives will be skipped. Let's test with forced dimensions in the repository.

        $asset = $this->service->upload($file, 'uploader-001', null, MediaVisibility::Public);

        // Manually update the stored asset to have larger dimensions for derivative testing
        $largeAsset = MediaAsset::create(
            id: $asset->id,
            uploaderId: $asset->uploaderId,
            filename: $asset->filename,
            storagePath: $asset->storagePath,
            disk: $asset->disk,
            mimeType: $asset->mimeType,
            fileSize: $asset->fileSize,
            fileHash: $asset->fileHash,
            width: 2000,
            height: 1500,
            tenantId: null,
            visibility: MediaVisibility::Public,
        );
        $this->repository->save($largeAsset);

        $this->service->generateDerivatives($asset->id);

        $derivatives = $this->repository->findDerivatives($asset->id);

        // Width=2000 >= all variant targets except large_1920 is also <= 2000
        // Variants: thumb_480 (480), medium_960 (960), large_1920 (1920), blur_20 (20)
        // All 4 targets <= 2000, so 4 variants x 1 format (webp) = 4 derivatives
        self::assertCount(4, $derivatives);

        $variantNames = array_map(static fn(MediaDerivative $d) => $d->variant, $derivatives);
        self::assertContains('thumb_480', $variantNames);
        self::assertContains('medium_960', $variantNames);
        self::assertContains('large_1920', $variantNames);
        self::assertContains('blur_20', $variantNames);

        foreach ($derivatives as $derivative) {
            self::assertSame('webp', $derivative->format);
            self::assertTrue($this->disk->exists($derivative->storagePath));
        }
    }

    #[Test]
    public function test_upload_svg_with_scripts_sanitized(): void
    {
        $svgWithScript = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert("xss")</script><rect width="100" height="100"/></svg>';
        $file = new StubUploadedFile($svgWithScript, 'icon.svg', 'image/svg+xml');

        $asset = $this->service->upload($file, 'uploader-001', null, MediaVisibility::Public);

        // The stored content must have script tags removed
        $storedContent = $this->disk->read($asset->storagePath);
        self::assertStringNotContainsString('<script>', $storedContent);
        self::assertStringNotContainsString('alert', $storedContent);
        self::assertStringContainsString('<rect', $storedContent);
    }

    #[Test]
    public function test_upload_with_mismatched_mime_rejected(): void
    {
        // Send PNG magic bytes but declare JPEG MIME type
        $pngContent = $this->createMinimalPng();
        $file = new StubUploadedFile($pngContent, 'photo.jpg', 'image/jpeg');

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('does not match');

        $this->service->upload($file, 'uploader-001', null, MediaVisibility::Public);
    }

    #[Test]
    public function test_upload_oversized_file_rejected(): void
    {
        $config = new MediaConfig(
            maxUploadSize: 100,
        );

        $service = new MediaService(
            disk: $this->disk,
            imageProcessor: $this->imageProcessor,
            fileValidator: new FileValidator($config),
            svgSanitizer: new SvgSanitizer(),
            repository: $this->repository,
            config: $config,
            auditLogger: $this->auditLogger,
            filenameSanitizer: new FilenameSanitizer(),
            pdfValidator: new PdfValidator(),
            logger: new NullTestLogger(),
            variantGenerator: new ImageVariantGenerator($this->imageProcessor, $this->disk),
        );

        $jpegContent = $this->createMinimalJpeg() . str_repeat('X', 200);
        $file = new StubUploadedFile($jpegContent, 'big.jpg', 'image/jpeg');

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('exceeds maximum');

        $service->upload($file, 'uploader-001', null, MediaVisibility::Public);
    }

    #[Test]
    public function test_upload_decompression_bomb_rejected(): void
    {
        $config = new MediaConfig(
            maxPixelCount: 100,
        );

        $service = new MediaService(
            disk: $this->disk,
            imageProcessor: $this->imageProcessor,
            fileValidator: new FileValidator($config),
            svgSanitizer: new SvgSanitizer(),
            repository: $this->repository,
            config: $config,
            auditLogger: $this->auditLogger,
            filenameSanitizer: new FilenameSanitizer(),
            pdfValidator: new PdfValidator(),
            logger: new NullTestLogger(),
            variantGenerator: new ImageVariantGenerator($this->imageProcessor, $this->disk),
        );

        // Use a JPEG that getimagesize reads as > 100 pixels (our minimal is 1x1 = 1 pixel, so it passes)
        // We need a slightly larger image. Create a 20x20 JPEG = 400 pixels > 100 max.
        $jpegContent = $this->createJpegWithDimensions(20, 20);
        $file = new StubUploadedFile($jpegContent, 'bomb.jpg', 'image/jpeg');

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('pixel count');

        $service->upload($file, 'uploader-001', null, MediaVisibility::Public);
    }

    #[Test]
    public function test_exif_extracted_and_stored_when_preserve_enabled(): void
    {
        $this->imageProcessor->setExifData(['Make' => 'TestCam', 'Model' => 'X100']);

        $jpegContent = $this->createMinimalJpeg();
        $file = new StubUploadedFile($jpegContent, 'photo-exif.jpg', 'image/jpeg');

        $asset = $this->service->upload($file, 'uploader-001', null, MediaVisibility::Public);

        // When preserveExif=true, EXIF should be extracted but NOT stripped
        self::assertNotNull($asset->exifData);
        self::assertSame('TestCam', $asset->exifData['Make']);
        self::assertSame('X100', $asset->exifData['Model']);
    }

    #[Test]
    public function test_exif_stripped_when_preserve_disabled(): void
    {
        $config = new MediaConfig(
            preserveExif: false,
        );

        $this->imageProcessor->setExifData(['Make' => 'TestCam', 'Model' => 'X100']);

        $service = new MediaService(
            disk: $this->disk,
            imageProcessor: $this->imageProcessor,
            fileValidator: new FileValidator($config),
            svgSanitizer: new SvgSanitizer(),
            repository: $this->repository,
            config: $config,
            auditLogger: $this->auditLogger,
            filenameSanitizer: new FilenameSanitizer(),
            pdfValidator: new PdfValidator(),
            logger: new NullTestLogger(),
            variantGenerator: new ImageVariantGenerator($this->imageProcessor, $this->disk),
        );

        $jpegContent = $this->createMinimalJpeg();
        $file = new StubUploadedFile($jpegContent, 'photo-strip.jpg', 'image/jpeg');

        $asset = $service->upload($file, 'uploader-001', null, MediaVisibility::Public);

        // When preserveExif=false, EXIF should be stripped — exifData is null
        self::assertNull($asset->exifData);
    }

    #[Test]
    public function test_media_soft_delete(): void
    {
        $jpegContent = $this->createMinimalJpeg();
        $file = new StubUploadedFile($jpegContent, 'deleteme.jpg', 'image/jpeg');

        $asset = $this->service->upload($file, 'uploader-001', null, MediaVisibility::Public);

        self::assertTrue($this->disk->exists($asset->storagePath));

        $this->service->delete($asset->id, 'Inappropriate content');

        // Disk file should be removed
        self::assertFalse($this->disk->exists($asset->storagePath));

        // Repository should have soft-deleted the record
        $found = $this->repository->findById($asset->id);
        self::assertNull($found);

        // Audit log should record the deletion
        $entries = $this->auditLogger->getEntries();
        $deleteEntry = array_filter($entries, static fn(array $e) => $e['action'] === 'cms.media.delete');
        self::assertCount(1, $deleteEntry);
    }

    #[Test]
    public function test_alt_text_per_locale_saved_and_retrieved(): void
    {
        $jpegContent = $this->createMinimalJpeg();
        $file = new StubUploadedFile($jpegContent, 'localized.jpg', 'image/jpeg');

        $asset = $this->service->upload($file, 'uploader-001', null, MediaVisibility::Public);

        // Save translations for multiple locales
        $translationEn = new MediaAssetTranslation(
            mediaAssetId: $asset->id,
            locale: 'en',
            altText: 'A beautiful sunset',
            caption: 'Sunset over the mountains',
            title: 'Mountain Sunset',
        );
        $translationFr = new MediaAssetTranslation(
            mediaAssetId: $asset->id,
            locale: 'fr',
            altText: 'Un beau coucher de soleil',
            caption: 'Coucher de soleil sur les montagnes',
            title: 'Coucher de soleil montagneux',
        );

        $this->repository->saveTranslation($translationEn);
        $this->repository->saveTranslation($translationFr);

        $translations = $this->repository->findTranslations($asset->id);
        self::assertCount(2, $translations);

        $locales = array_map(static fn(MediaAssetTranslation $t) => $t->locale, $translations);
        self::assertContains('en', $locales);
        self::assertContains('fr', $locales);

        $en = array_values(array_filter($translations, static fn(MediaAssetTranslation $t) => $t->locale === 'en'))[0];
        self::assertSame('A beautiful sunset', $en->altText);
        self::assertSame('Sunset over the mountains', $en->caption);
        self::assertSame('Mountain Sunset', $en->title);
    }

    #[Test]
    public function test_duplicate_upload_returns_existing_asset(): void
    {
        $jpegContent = $this->createMinimalJpeg();
        $file1 = new StubUploadedFile($jpegContent, 'first.jpg', 'image/jpeg');
        $file2 = new StubUploadedFile($jpegContent, 'second.jpg', 'image/jpeg');

        $asset1 = $this->service->upload($file1, 'uploader-001', null, MediaVisibility::Public);
        $asset2 = $this->service->upload($file2, 'uploader-002', null, MediaVisibility::Public);

        // Same content hash should return the same asset
        self::assertSame($asset1->id, $asset2->id);
        self::assertSame($asset1->fileHash, $asset2->fileHash);
    }

    #[Test]
    public function test_upload_png_stores_correct_mime_type(): void
    {
        $pngContent = $this->createMinimalPng();
        $file = new StubUploadedFile($pngContent, 'image.png', 'image/png');

        $asset = $this->service->upload($file, 'uploader-001', null, MediaVisibility::Public);

        self::assertSame('image/png', $asset->mimeType);
        self::assertNotEmpty($asset->fileHash);
        self::assertTrue($this->disk->exists($asset->storagePath));
    }

    #[Test]
    public function test_upload_disallowed_extension_rejected(): void
    {
        $content = 'not really an executable';
        $file = new StubUploadedFile($content, 'malware.exe', 'application/octet-stream');

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('not allowed');

        $this->service->upload($file, 'uploader-001', null, MediaVisibility::Public);
    }

    #[Test]
    public function test_media_listing_with_filters(): void
    {
        // Upload several assets
        $jpeg = $this->createMinimalJpeg();
        $png = $this->createMinimalPng();

        $this->service->upload(
            new StubUploadedFile($jpeg, 'a.jpg', 'image/jpeg'),
            'uploader-001',
            'tenant-1',
            MediaVisibility::Public,
        );
        $this->service->upload(
            new StubUploadedFile($png, 'b.png', 'image/png'),
            'uploader-001',
            'tenant-1',
            MediaVisibility::Private,
        );

        // List all for tenant
        $all = $this->repository->listAssets('tenant-1', 1, 20);
        self::assertSame(2, $all->total);

        // Filter by MIME type
        $jpegOnly = $this->repository->listAssets('tenant-1', 1, 20, 'image/jpeg');
        self::assertSame(1, $jpegOnly->total);
        self::assertSame('image/jpeg', $jpegOnly->items[0]->mimeType);

        // Filter by visibility
        $privateOnly = $this->repository->listAssets('tenant-1', 1, 20, null, 'private');
        self::assertSame(1, $privateOnly->total);
        self::assertSame(MediaVisibility::Private, $privateOnly->items[0]->visibility);
    }

    #[Test]
    public function test_get_public_url_returns_original_and_derivative_urls(): void
    {
        $jpegContent = $this->createMinimalJpeg();
        $file = new StubUploadedFile($jpegContent, 'urltest.jpg', 'image/jpeg');

        $asset = $this->service->upload($file, 'uploader-001', null, MediaVisibility::Public);

        // Upload a large version for derivatives
        $largeAsset = MediaAsset::create(
            id: $asset->id,
            uploaderId: $asset->uploaderId,
            filename: $asset->filename,
            storagePath: $asset->storagePath,
            disk: $asset->disk,
            mimeType: $asset->mimeType,
            fileSize: $asset->fileSize,
            fileHash: $asset->fileHash,
            width: 2000,
            height: 1500,
            tenantId: null,
            visibility: MediaVisibility::Public,
        );
        $this->repository->save($largeAsset);
        $this->service->generateDerivatives($asset->id);

        // Original URL
        $originalUrl = $this->service->getPublicUrl($asset->id);
        self::assertStringContainsString($asset->storagePath, $originalUrl);

        // Derivative URL
        $thumbUrl = $this->service->getPublicUrl($asset->id, 'thumb_480', 'webp');
        self::assertStringContainsString('derivatives', $thumbUrl);
        self::assertStringContainsString('thumb_480', $thumbUrl);
    }

    #[Test]
    public function test_delete_nonexistent_asset_throws(): void
    {
        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('Media asset not found');

        $this->service->delete('nonexistent-id', 'cleanup');
    }

    /**
     * Create a minimal valid 1x1 JPEG binary.
     */
    private function createMinimalJpeg(): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'test_jpeg_');

        if ($tempPath === false) {
            self::fail('Could not create temporary file');
        }

        $img = imagecreatetruecolor(1, 1);

        if ($img === false) {
            unlink($tempPath);
            self::fail('Could not create GD image');
        }

        imagejpeg($img, $tempPath);

        $content = file_get_contents($tempPath);
        unlink($tempPath);

        if ($content === false) {
            self::fail('Could not read JPEG content');
        }

        return $content;
    }

    /**
     * Create a minimal valid PNG binary.
     */
    private function createMinimalPng(): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'test_png_');

        if ($tempPath === false) {
            self::fail('Could not create temporary file');
        }

        $img = imagecreatetruecolor(1, 1);

        if ($img === false) {
            unlink($tempPath);
            self::fail('Could not create GD image');
        }

        imagepng($img, $tempPath);

        $content = file_get_contents($tempPath);
        unlink($tempPath);

        if ($content === false) {
            self::fail('Could not read PNG content');
        }

        return $content;
    }

    /**
     * Create a JPEG with specific dimensions.
     */
    private function createJpegWithDimensions(int $width, int $height): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'test_jpeg_dim_');

        if ($tempPath === false) {
            self::fail('Could not create temporary file');
        }

        $img = imagecreatetruecolor(max(1, $width), max(1, $height));

        if ($img === false) {
            unlink($tempPath);
            self::fail('Could not create GD image');
        }

        imagejpeg($img, $tempPath);

        $content = file_get_contents($tempPath);
        unlink($tempPath);

        if ($content === false) {
            self::fail('Could not read JPEG content');
        }

        return $content;
    }
}

/**
 * In-memory media disk for integration testing.
 */
final class InMemoryMediaDisk implements MediaDiskInterface
{
    /** @var array<string, string> */
    private array $files = [];

    #[Override]
    public function write(string $path, string $contents): void
    {
        $this->files[$path] = $contents;
    }

    #[Override]
    public function read(string $path): string
    {
        return $this->files[$path] ?? '';
    }

    #[Override]
    public function delete(string $path): void
    {
        unset($this->files[$path]);
    }

    #[Override]
    public function exists(string $path): bool
    {
        return isset($this->files[$path]);
    }

    #[Override]
    public function url(string $path): string
    {
        return 'https://cdn.example.com/' . $path;
    }
}

/**
 * In-memory media repository for integration testing.
 */
final class InMemoryMediaRepository implements MediaRepositoryInterface
{
    /** @var array<string, MediaAsset> */
    private array $assets = [];

    /** @var array<string, list<MediaDerivative>> */
    private array $derivatives = [];

    /** @var array<string, list<MediaAssetTranslation>> */
    private array $translations = [];

    #[Override]
    public function findById(string $id): ?MediaAsset
    {
        $asset = $this->assets[$id] ?? null;

        if ($asset !== null && $asset->deletedAt !== null) {
            return null;
        }

        return $asset;
    }

    #[Override]
    public function findByHash(string $hash): ?MediaAsset
    {
        foreach ($this->assets as $asset) {
            if ($asset->fileHash === $hash && $asset->deletedAt === null) {
                return $asset;
            }
        }

        return null;
    }

    #[Override]
    public function listAssets(
        ?string $tenantId,
        int $page,
        int $perPage,
        ?string $mimeType = null,
        ?string $visibility = null,
    ): PaginationResult {
        $items = array_filter(
            $this->assets,
            static fn(MediaAsset $a) => $a->deletedAt === null
                && ($tenantId === null || $a->tenantId === $tenantId)
                && ($mimeType === null || $a->mimeType === $mimeType)
                && ($visibility === null || $a->visibility->value === $visibility),
        );

        $items = array_values($items);
        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($items, $offset, $perPage);

        return new PaginationResult(
            items: $paged,
            total: $total,
            hasMore: ($offset + $perPage) < $total,
            perPage: $perPage,
            currentPage: $page,
            lastPage: (int) ceil($total / $perPage) ?: 1,
        );
    }

    #[Override]
    public function save(MediaAsset $asset): void
    {
        $this->assets[$asset->id] = $asset;
    }

    #[Override]
    public function delete(MediaAsset $asset): void
    {
        // Soft-delete: replace with a deleted version
        $deleted = new MediaAsset(
            id: $asset->id,
            tenantId: $asset->tenantId,
            uploaderId: $asset->uploaderId,
            filename: $asset->filename,
            storagePath: $asset->storagePath,
            disk: $asset->disk,
            mimeType: $asset->mimeType,
            fileSize: $asset->fileSize,
            fileHash: $asset->fileHash,
            width: $asset->width,
            height: $asset->height,
            exifData: $asset->exifData,
            altTextDefault: $asset->altTextDefault,
            visibility: $asset->visibility,
            dataClassification: $asset->dataClassification,
            createdAt: $asset->createdAt,
            updatedAt: $asset->updatedAt,
            deletedAt: new DateTimeImmutable(),
        );
        $this->assets[$asset->id] = $deleted;
    }

    #[Override]
    public function findDerivatives(string $assetId): array
    {
        return $this->derivatives[$assetId] ?? [];
    }

    #[Override]
    public function saveDerivative(MediaDerivative $derivative): void
    {
        $this->derivatives[$derivative->mediaAssetId][] = $derivative;
    }

    #[Override]
    public function saveTranslation(MediaAssetTranslation $translation): void
    {
        $this->translations[$translation->mediaAssetId][] = $translation;
    }

    #[Override]
    public function findTranslations(string $assetId): array
    {
        return $this->translations[$assetId] ?? [];
    }
}

/**
 * Stub image processor for integration testing.
 */
final class StubImageProcessor implements ImageProcessorInterface
{
    /** @var array<string, mixed> */
    private array $exifData = [];

    private ?int $forcedWidth = null;
    private ?int $forcedHeight = null;

    /**
     * @param array<string, mixed> $data
     */
    public function setExifData(array $data): void
    {
        $this->exifData = $data;
    }

    public function setForcedDimensions(int $width, int $height): void
    {
        $this->forcedWidth = $width;
        $this->forcedHeight = $height;
    }

    #[Override]
    public function resize(string $sourcePath, int $width, ?int $height, string $format): string
    {
        // Create a real image file at a temp path so getimagesize works
        $tempPath = tempnam(sys_get_temp_dir(), 'stub_resize_');

        if ($tempPath === false) {
            return '';
        }

        $effectiveWidth = $this->forcedWidth ?? $width;
        $effectiveHeight = $this->forcedHeight ?? ($height ?? $width);

        $img = imagecreatetruecolor(max(1, $effectiveWidth), max(1, $effectiveHeight));

        if ($img === false) {
            return $tempPath;
        }

        // Always write as PNG for reliability (getimagesize will work)
        imagepng($img, $tempPath);

        return $tempPath;
    }

    #[Override]
    public function generateBlurPlaceholder(string $sourcePath): string
    {
        return 'data:image/webp;base64,UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA';
    }

    #[Override]
    public function extractExif(string $sourcePath): array
    {
        return $this->exifData;
    }

    #[Override]
    public function stripExif(string $sourcePath): string
    {
        // Copy the source to a temp file, simulating stripping
        $tempPath = tempnam(sys_get_temp_dir(), 'stub_strip_');

        if ($tempPath === false) {
            return $sourcePath;
        }

        $content = file_get_contents($sourcePath);

        if ($content !== false) {
            file_put_contents($tempPath, $content);
        }

        return $tempPath;
    }
}

/**
 * Stub uploaded file implementing PSR-7 UploadedFileInterface.
 */
final readonly class StubUploadedFile implements UploadedFileInterface
{
    private string $content;

    public function __construct(
        string $content,
        private string $clientFilename,
        private string $clientMediaType,
    ) {
        $this->content = $content;
    }

    #[Override]
    public function getStream(): StreamInterface
    {
        return Stream::create($this->content);
    }

    #[Override]
    public function moveTo(string $targetPath): void
    {
        file_put_contents($targetPath, $this->content);
    }

    #[Override]
    public function getSize(): int
    {
        return strlen($this->content);
    }

    #[Override]
    public function getError(): int
    {
        return UPLOAD_ERR_OK;
    }

    #[Override]
    public function getClientFilename(): string
    {
        return $this->clientFilename;
    }

    #[Override]
    public function getClientMediaType(): string
    {
        return $this->clientMediaType;
    }
}

/**
 * Stub audit logger for media upload integration tests.
 */
final class MediaUploadStubAuditLogger implements AuditLoggerInterface
{
    /** @var list<array<string, mixed>> */
    private array $entries = [];

    #[Override]
    public function log(
        AuditEvent $event,
        AuditOutcome $outcome,
        ?string $actor,
        string $action,
        string $resource = '',
        array $metadata = [],
    ): AuditEntry {
        $this->entries[] = [
            'event' => $event,
            'outcome' => $outcome,
            'actor' => $actor,
            'action' => $action,
            'resource' => $resource,
            'metadata' => $metadata,
        ];

        return new AuditEntry(
            id: 'audit-' . count($this->entries),
            event: $event,
            outcome: $outcome,
            actor: $actor ?? '',
            action: $action,
            resource: $resource,
            timestamp: new DateTimeImmutable(),
            metadata: $metadata,
            previousHmac: '',
            hmac: '',
        );
    }

    /** @return list<array<string, mixed>> */
    public function getEntries(): array
    {
        return $this->entries;
    }
}

/**
 * Minimal PSR-3 logger for testing.
 */
final class NullTestLogger implements LoggerInterface
{
    #[Override]
    public function emergency(string|Stringable $message, array $context = []): void {}

    #[Override]
    public function alert(string|Stringable $message, array $context = []): void {}

    #[Override]
    public function critical(string|Stringable $message, array $context = []): void {}

    #[Override]
    public function error(string|Stringable $message, array $context = []): void {}

    #[Override]
    public function warning(string|Stringable $message, array $context = []): void {}

    #[Override]
    public function notice(string|Stringable $message, array $context = []): void {}

    #[Override]
    public function info(string|Stringable $message, array $context = []): void {}

    #[Override]
    public function debug(string|Stringable $message, array $context = []): void {}

    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void {}
}
