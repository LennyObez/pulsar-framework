<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Pulsar\Api\Internal;
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

        $page = max(1, is_int($params['page'] ?? null) ? $params['page'] : 1);
        $perPage = min(100, max(1, is_int($params['per_page'] ?? null) ? $params['per_page'] : 20));
        $mimeType = is_string($params['mime'] ?? null) ? $params['mime'] : null;

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
    public function show(ServerRequestInterface $request, string $id): Response
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

        $visibilityValue = is_string($body['visibility'] ?? null) ? $body['visibility'] : 'public';
        $visibility = MediaVisibility::tryFrom($visibilityValue) ?? MediaVisibility::Public;
        $uploaderId = is_string($body['uploader_id'] ?? null) ? $body['uploader_id'] : 'api';

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
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $asset = $this->mediaRepository->findById($id);

        if ($asset === null) {
            return Response::json(['error' => 'Media asset not found', 'status' => 404], 404);
        }

        try {
            $this->mediaService->delete($id, 'Deleted via REST API');

            return Response::json([
                'data' => ['id' => $id, 'status' => 'deleted'],
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage(), 'status' => 422], 422);
        }
    }
}
