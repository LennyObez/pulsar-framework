<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media;

use DateTimeImmutable;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Config\MediaConfig;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Media\Security\FilenameSanitizer;
use Pulsar\Extension\Cms\Media\Security\FileValidator;
use Pulsar\Extension\Cms\Media\Security\PdfValidator;
use Pulsar\Extension\Cms\Media\Security\SvgSanitizer;
use Pulsar\Extension\Cms\Support\UuidGenerator;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function array_keys;
use function array_map;
use function bin2hex;
use function count;
use function date;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function getimagesize;
use function pathinfo;
use function sodium_crypto_generichash;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Core media service orchestrating upload, derivative generation, and retrieval.
 */
#[Internal(reason: 'Use MediaServiceInterface for public API')]
final readonly class MediaService implements MediaServiceInterface
{
    /**
     * Derivative variant definitions: [name => target width].
     *
     * @var array<string, int>
     */
    private const array DERIVATIVE_VARIANTS = [
        'thumb_480' => 480,
        'medium_960' => 960,
        'large_1920' => 1920,
        'blur_20' => 20,
    ];

    /**
     * Output formats for derivatives.
     *
     * @var list<string>
     */
    private const array DERIVATIVE_FORMATS = ['webp'];

    public function __construct(
        private MediaDiskInterface $disk,
        private ImageProcessorInterface $imageProcessor,
        private FileValidator $fileValidator,
        private SvgSanitizer $svgSanitizer,
        private MediaRepositoryInterface $repository,
        private MediaConfig $config,
        private ?AuditLoggerInterface $auditLogger,
        private FilenameSanitizer $filenameSanitizer,
        private PdfValidator $pdfValidator,
        private LoggerInterface $logger,
        private ImageVariantGenerator $variantGenerator,
    ) {}

    public function upload(
        UploadedFileInterface $file,
        string $uploaderId,
        ?string $tenantId,
        MediaVisibility $visibility,
    ): MediaAsset {
        $stream = $file->getStream();
        $contents = (string) $stream;
        $fileSize = $file->getSize() ?? strlen($contents);
        $originalFilename = $file->getClientFilename() ?? 'unknown';
        $declaredMime = $file->getClientMediaType() ?? 'application/octet-stream';

        // Write to a temporary file for validation
        $tempPath = tempnam(sys_get_temp_dir(), 'pulsar_upload_');

        if ($tempPath === false) {
            throw CmsException::invalidImageFile();
        }

        file_put_contents($tempPath, $contents);

        try {
            // Validate through the security pipeline and dispatch format-specific
            // handling on the CANONICAL magic-byte-detected MIME it returns — never
            // the attacker-controlled declared type. A declared `Image/SVG+XML`
            // passes the case-insensitive consistency check but would miss a
            // case-sensitive `=== 'image/svg+xml'` dispatch, skipping sanitization
            // and storing a scriptable SVG (stored XSS).
            $detectedMime = $this->fileValidator->validate($tempPath, $originalFilename, $declaredMime, $fileSize);

            if ($detectedMime === 'image/svg+xml') {
                $contents = $this->svgSanitizer->sanitize($contents);
            }

            if ($detectedMime === 'application/pdf') {
                $this->pdfValidator->validate($tempPath);
            }

            // Compute BLAKE2b file hash via libsodium
            $fileHash = bin2hex(sodium_crypto_generichash($contents));

            // Check for duplicate by hash
            $existing = $this->repository->findByHash($fileHash);

            if ($existing !== null) {
                return $existing;
            }

            // Sanitize filename and compute storage path
            $sanitizedFilename = $this->filenameSanitizer->sanitize($originalFilename, $fileHash);
            $storagePath = $this->buildStoragePath($tenantId, $fileHash, $sanitizedFilename);

            // Extract image dimensions
            $width = null;
            $height = null;
            $exifData = null;

            if (str_starts_with($declaredMime, 'image/') && $declaredMime !== 'image/svg+xml') {
                $info = @getimagesize($tempPath);

                if ($info !== false) {
                    $width = $info[0];
                    $height = $info[1];
                }

                // Extract or strip EXIF
                if ($declaredMime === 'image/jpeg') {
                    $exifData = $this->imageProcessor->extractExif($tempPath);

                    if (!$this->config->preserveExif) {
                        $strippedPath = $this->imageProcessor->stripExif($tempPath);
                        $contents = file_get_contents($strippedPath) ?: $contents;
                        unlink($strippedPath);
                        $exifData = null;
                    }
                }
            }

            // Store the file
            $this->disk->write($storagePath, $contents);

            // Generate ID
            $id = UuidGenerator::v7();

            // Create the asset record
            $asset = MediaAsset::create(
                id: $id,
                uploaderId: $uploaderId,
                filename: $sanitizedFilename,
                storagePath: $storagePath,
                disk: $this->config->disk,
                mimeType: $declaredMime,
                fileSize: $fileSize,
                fileHash: $fileHash,
                width: $width,
                height: $height,
                exifData: $exifData,
                tenantId: $tenantId,
                visibility: $visibility,
            );

            $this->repository->save($asset);

            $this->auditLogger?->log(
                AuditEvent::DataModification,
                AuditOutcome::Success,
                $uploaderId,
                'cms.media.upload',
                $id,
                ['filename' => $sanitizedFilename, 'mime_type' => $declaredMime, 'file_size' => $fileSize],
            );

            $this->logger->info('Media asset uploaded', [
                'id' => $id,
                'filename' => $sanitizedFilename,
                'mime_type' => $declaredMime,
                'file_size' => $fileSize,
            ]);

            return $asset;
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    public function generateDerivatives(string $assetId): void
    {
        $asset = $this->repository->findById($assetId);

        if ($asset === null) {
            throw CmsException::mediaNotFound($assetId);
        }

        if (!$asset->isImage() || $asset->mimeType === 'image/svg+xml') {
            return;
        }

        $originalContents = $this->disk->read($asset->storagePath);
        $tempOriginal = tempnam(sys_get_temp_dir(), 'pulsar_deriv_');

        if ($tempOriginal === false) {
            return;
        }

        file_put_contents($tempOriginal, $originalContents);

        try {
            $formats = self::DERIVATIVE_FORMATS;

            if ($this->config->avifEnabled) {
                $formats[] = 'avif';
            }

            foreach (self::DERIVATIVE_VARIANTS as $variant => $targetWidth) {
                // Skip if source is smaller than target
                if ($asset->width !== null && $asset->width < $targetWidth) {
                    continue;
                }

                foreach ($formats as $format) {
                    $resizedPath = $this->imageProcessor->resize($tempOriginal, $targetWidth, null, $format);
                    $derivativeContents = file_get_contents($resizedPath);

                    if ($derivativeContents === false) {
                        unlink($resizedPath);

                        continue;
                    }

                    $derivativeHash = bin2hex(sodium_crypto_generichash($derivativeContents));
                    $derivativeInfo = @getimagesize($resizedPath);
                    $derivativeWidth = $derivativeInfo !== false ? $derivativeInfo[0] : $targetWidth;
                    $derivativeHeight = $derivativeInfo !== false ? $derivativeInfo[1] : 0;

                    $derivativeStoragePath = $this->buildDerivativePath(
                        $asset->storagePath,
                        $variant,
                        $format,
                    );

                    $this->disk->write($derivativeStoragePath, $derivativeContents);

                    $derivative = new MediaDerivative(
                        id: UuidGenerator::v7(),
                        mediaAssetId: $assetId,
                        variant: $variant,
                        format: $format,
                        storagePath: $derivativeStoragePath,
                        fileSize: strlen($derivativeContents),
                        width: $derivativeWidth,
                        height: $derivativeHeight,
                        fileHash: $derivativeHash,
                        createdAt: new DateTimeImmutable(),
                    );

                    $this->repository->saveDerivative($derivative);
                    unlink($resizedPath);
                }
            }

            // Generate configurable image variants
            $imageVariants = $this->variantGenerator->generate(
                $tempOriginal,
                $asset->storagePath,
                $this->config->imageVariants,
            );

            foreach ($imageVariants as $variant) {
                $variantHash = bin2hex(sodium_crypto_generichash(
                    $this->disk->read($variant->path),
                ));

                $derivative = new MediaDerivative(
                    id: UuidGenerator::v7(),
                    mediaAssetId: $assetId,
                    variant: $variant->format . '_' . $variant->width . 'x' . $variant->height,
                    format: $variant->format,
                    storagePath: $variant->path,
                    fileSize: $variant->sizeBytes,
                    width: $variant->width,
                    height: $variant->height,
                    fileHash: $variantHash,
                    createdAt: new DateTimeImmutable(),
                );

                $this->repository->saveDerivative($derivative);
            }

            $this->logger->info('Media derivatives generated', [
                'asset_id' => $assetId,
                'variants' => array_keys(self::DERIVATIVE_VARIANTS),
                'image_variants' => count($imageVariants),
            ]);
        } finally {
            if (file_exists($tempOriginal)) {
                unlink($tempOriginal);
            }
        }
    }

    public function getPublicUrl(string $assetId, ?string $variant = null, ?string $format = null): string
    {
        $asset = $this->repository->findById($assetId);

        if ($asset === null) {
            throw CmsException::mediaNotFound($assetId);
        }

        if ($variant !== null) {
            $derivatives = $this->repository->findDerivatives($assetId);

            foreach ($derivatives as $derivative) {
                if ($derivative->variant === $variant && ($format === null || $derivative->format === $format)) {
                    return $this->disk->url($derivative->storagePath);
                }
            }
        }

        return $this->disk->url($asset->storagePath);
    }

    public function sanitizeSvg(string $svgContent): string
    {
        return $this->svgSanitizer->sanitize($svgContent);
    }

    public function getVariants(string $mediaId): array
    {
        $asset = $this->repository->findById($mediaId);

        if ($asset === null) {
            throw CmsException::mediaNotFound($mediaId);
        }

        $derivatives = $this->repository->findDerivatives($mediaId);

        return array_map(
            static fn(MediaDerivative $d): ImageVariant => new ImageVariant(
                path: $d->storagePath,
                width: $d->width,
                height: $d->height,
                format: $d->format,
                sizeBytes: $d->fileSize,
            ),
            $derivatives,
        );
    }

    public function delete(string $assetId, string $reason): void
    {
        $asset = $this->repository->findById($assetId);

        if ($asset === null) {
            throw CmsException::mediaNotFound($assetId);
        }

        // Delete derivatives from disk
        $derivatives = $this->repository->findDerivatives($assetId);

        foreach ($derivatives as $derivative) {
            $this->disk->delete($derivative->storagePath);
        }

        // Delete original from disk
        $this->disk->delete($asset->storagePath);

        // Soft-delete the record
        $this->repository->delete($asset);

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            null,
            'cms.media.delete',
            $assetId,
            ['reason' => $reason, 'filename' => $asset->filename],
        );

        $this->logger->info('Media asset deleted', [
            'id' => $assetId,
            'reason' => $reason,
        ]);
    }

    /**
     * Build the storage path for an uploaded file.
     *
     * Strategy: {tenant_id_or_default}/{year}/{month}/{hash_prefix_2}/{filename}
     */
    private function buildStoragePath(?string $tenantId, string $fileHash, string $filename): string
    {
        $tenant = $tenantId ?? 'default';

        return sprintf(
            '%s/%s/%s/%s/%s',
            $tenant,
            date('Y'),
            date('m'),
            substr($fileHash, 0, 2),
            $filename,
        );
    }

    /**
     * Build the storage path for a derivative.
     */
    private function buildDerivativePath(string $originalPath, string $variant, string $format): string
    {
        $pathInfo = pathinfo($originalPath);
        $dir = $pathInfo['dirname'] ?? '';
        $name = $pathInfo['filename'] ?? 'unknown';

        return sprintf('%s/derivatives/%s_%s.%s', $dir, $name, $variant, $format);
    }
}
