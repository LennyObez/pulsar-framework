<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Commerce\ApiKey;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaServiceInterface;
use Pulsar\Extension\Cms\Media\MediaVisibility;
use Pulsar\Http\Message\Response;

use function array_map;
use function is_int;
use function is_string;
use function max;
use function min;
use function sprintf;

/**
 * Public REST API controller for CMS media assets.
 *
 * Provides JSON endpoints for listing, retrieving, uploading,
 * and deleting media assets.
 */
#[Internal(reason: 'CMS REST API controller; implementation detail')]
final readonly class MediaApiController
{
    public function __construct(
        private MediaServiceInterface $mediaService,
        private MediaRepositoryInterface $mediaRepository,
    ) {}

    /**
     * GET /api/v1/media: List media assets with pagination.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();

        /** @var mixed $rawPage */
        $rawPage = $params['page'] ?? null;
        $page = max(1, is_int($rawPage) ? $rawPage : 1);
        /** @var mixed $rawPerPage */
        $rawPerPage = $params['per_page'] ?? null;
        $perPage = min(100, max(1, is_int($rawPerPage) ? $rawPerPage : 20));
        /** @var mixed $rawMime */
        $rawMime = $params['mime'] ?? null;
        $mimeType = is_string($rawMime) ? $rawMime : null;

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $result = $this->mediaRepository->listAssets(
            tenantId: $tenantId,
            page: $page,
            perPage: $perPage,
            mimeType: $mimeType,
        );

        $data = array_map(static fn(MediaAsset $a) => [
            'id' => $a->id,
            'filename' => $a->filename,
            'mime_type' => $a->mimeType,
            'file_size' => $a->fileSize,
            'width' => $a->width,
            'height' => $a->height,
            'visibility' => $a->visibility->value,
            'created_at' => $a->createdAt->format('c'),
        ], $result->items);

        return Response::json([
            'data' => $data,
            'pagination' => $result->metaToArray(),
        ])
            ->withHeader('X-Total-Count', (string) ($result->total ?? 0))
            ->withHeader('X-Page', (string) $page)
            ->withHeader('X-Per-Page', (string) $perPage);
    }

    /**
     * GET /api/v1/media/{id}: Show a single media asset.
     */
    public function show(string $id): Response
    {
        $asset = $this->mediaRepository->findById($id);

        if ($asset === null) {
            return Response::json(['error' => 'Media asset not found', 'status' => 404], 404);
        }

        return Response::json([
            'data' => [
                'id' => $asset->id,
                'filename' => $asset->filename,
                'mime_type' => $asset->mimeType,
                'file_size' => $asset->fileSize,
                'file_hash' => $asset->fileHash,
                'width' => $asset->width,
                'height' => $asset->height,
                'visibility' => $asset->visibility->value,
                'created_at' => $asset->createdAt->format('c'),
                'updated_at' => $asset->updatedAt->format('c'),
            ],
        ]);
    }

    /**
     * POST /api/v1/media: Upload a new media asset via multipart form data.
     */
    public function upload(ServerRequestInterface $request): Response
    {
        $uploadedFiles = $request->getUploadedFiles();

        /** @var UploadedFileInterface|null $file */
        $file = $uploadedFiles['file'] ?? null;

        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return Response::json([
                'error' => 'No valid file uploaded',
                'status' => 422,
                'details' => ['file' => 'A valid file upload is required'],
            ], 422);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawVisibility */
        $rawVisibility = $body['visibility'] ?? null;
        $visibilityValue = is_string($rawVisibility) ? $rawVisibility : 'public';
        $visibility = MediaVisibility::tryFrom($visibilityValue) ?? MediaVisibility::Public;
        /** @var mixed $rawUploaderId */
        $rawUploaderId = $body['uploader_id'] ?? null;
        $uploaderId = is_string($rawUploaderId) ? $rawUploaderId : 'api';

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        try {
            $asset = $this->mediaService->upload(
                file: $file,
                uploaderId: $uploaderId,
                tenantId: $tenantId,
                visibility: $visibility,
            );

            return Response::json([
                'data' => [
                    'id' => $asset->id,
                    'filename' => $asset->filename,
                    'mime_type' => $asset->mimeType,
                    'file_size' => $asset->fileSize,
                    'visibility' => $asset->visibility->value,
                ],
            ], 201);
        } catch (CmsException $e) {
            return Response::json([
                'error' => $e->getMessage(),
                'status' => 422,
            ], 422);
        }
    }

    /**
     * DELETE /api/v1/media/{id}: Delete a media asset.
     *
     * Requires an authenticated API key (CmsApiKeyMiddleware) and deletes the
     * asset only when it belongs to the caller's tenant. Without this an
     * anonymous caller who guesses an asset id could permanently destroy another
     * tenant's media; the deletion audit records the real acting key, not a
     * generic "REST API" actor.
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        /** @var mixed $apiKey */
        $apiKey = $request->getAttribute('cms_api_key');
        if (!$apiKey instanceof ApiKey) {
            return Response::json(['error' => 'Authentication required', 'status' => 401], 401);
        }

        $asset = $this->mediaRepository->findById($id);

        // Fail closed: an asset from another tenant (or none) is reported as 404,
        // so ids cannot be probed and no cross-tenant delete can occur.
        if ($asset === null || $asset->tenantId !== $apiKey->tenantId) {
            return Response::json(['error' => 'Media asset not found', 'status' => 404], 404);
        }

        try {
            $this->mediaService->delete($id, sprintf('Deleted via REST API by api-key %s', $apiKey->id));

            return Response::json([
                'data' => ['id' => $id, 'status' => 'deleted'],
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage(), 'status' => 422], 422);
        }
    }
}
