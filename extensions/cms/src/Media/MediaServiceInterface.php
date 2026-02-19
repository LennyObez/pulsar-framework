<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media;

use Psr\Http\Message\UploadedFileInterface;
use Pulsar\Api\Api;

/**
 * High-level media service for upload, derivative generation, and retrieval.
 */
#[Api(since: '1.0.0')]
interface MediaServiceInterface
{
    public function upload(
        UploadedFileInterface $file,
        string $uploaderId,
        ?string $tenantId,
        MediaVisibility $visibility,
    ): MediaAsset;

    public function generateDerivatives(string $assetId): void;

    public function getPublicUrl(string $assetId, ?string $variant = null, ?string $format = null): string;

    public function sanitizeSvg(string $svgContent): string;

    public function delete(string $assetId, string $reason): void;
}
