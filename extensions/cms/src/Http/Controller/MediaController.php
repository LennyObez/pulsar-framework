<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\MediaDiskInterface;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaVisibility;
use Pulsar\Http\Message\Response;

/**
 * Public-facing media delivery controller.
 *
 * Serves media asset files (originals and derivatives) with appropriate
 * cache headers based on visibility. Public assets get long-lived immutable
 * caching; private assets require authentication and get no-store.
 */
#[Internal(reason: 'CMS HTTP controller — implementation detail')]
final readonly class MediaController
{
    private const string CACHE_DERIVATIVE = 'public, max-age=2592000, immutable';
    private const string CACHE_ORIGINAL_PUBLIC = 'public, max-age=86400';
    private const string CACHE_PRIVATE = 'private, no-store';

    public function __construct(
        private MediaRepositoryInterface $mediaRepository,
        private MediaDiskInterface $disk,
    ) {}

    /**
     * Serve a media derivative by variant, hash, filename, and format.
     *
     * URL pattern: /media/{variant}/{hash}/{filename}.{format}
     */
    public function serve(
        ServerRequestInterface $request,
        string $variant,
        string $hash,
        string $filename,
        string $format,
    ): Response {
        $asset = $this->mediaRepository->findByHash($hash);

        if ($asset === null || $asset->isDeleted()) {
            return Response::json(['error' => 'Media not found'], 404);
        }

        if (!$this->canAccess($request, $asset)) {
            return Response::json(['error' => 'Authentication required'], 403);
        }

        $cacheControl = $asset->visibility === MediaVisibility::Public
            ? self::CACHE_DERIVATIVE
            : self::CACHE_PRIVATE;

        $derivatives = $this->mediaRepository->findDerivatives($asset->id);

        foreach ($derivatives as $derivative) {
            if ($derivative->variant === $variant && $derivative->format === $format) {
                $contents = $this->disk->read($derivative->storagePath);
                $mimeType = self::formatToMime($format);

                return (new Response(
                    statusCode: 200,
                    headers: [
                        'Content-Type' => $mimeType,
                        'Content-Length' => (string) $derivative->fileSize,
                        'Cache-Control' => $cacheControl,
                        'ETag' => '"' . $derivative->fileHash . '"',
                    ],
                    body: $contents,
                ));
            }
        }

        return Response::json(['error' => 'Derivative not found'], 404);
    }

    /**
     * Serve the original media asset file.
     *
     * URL pattern: /media/original/{hash}/{filename}
     */
    public function serveOriginal(
        ServerRequestInterface $request,
        string $hash,
        string $filename,
    ): Response {
        $asset = $this->mediaRepository->findByHash($hash);

        if ($asset === null || $asset->isDeleted()) {
            return Response::json(['error' => 'Media not found'], 404);
        }

        if (!$this->canAccess($request, $asset)) {
            return Response::json(['error' => 'Authentication required'], 403);
        }

        $cacheControl = $asset->visibility === MediaVisibility::Public
            ? self::CACHE_ORIGINAL_PUBLIC
            : self::CACHE_PRIVATE;

        $contents = $this->disk->read($asset->storagePath);

        return (new Response(
            statusCode: 200,
            headers: [
                'Content-Type' => $asset->mimeType,
                'Content-Length' => (string) $asset->fileSize,
                'Cache-Control' => $cacheControl,
                'ETag' => '"' . $asset->fileHash . '"',
            ],
            body: $contents,
        ));
    }

    /**
     * Check whether the request is allowed to access the given asset.
     * Public assets are accessible to everyone; private assets require authentication.
     */
    private function canAccess(ServerRequestInterface $request, MediaAsset $asset): bool
    {
        if ($asset->visibility === MediaVisibility::Public) {
            return true;
        }

        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        return $identity !== null && $identity->isAuthenticated();
    }

    private static function formatToMime(string $format): string
    {
        return match ($format) {
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'jpeg', 'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }
}
