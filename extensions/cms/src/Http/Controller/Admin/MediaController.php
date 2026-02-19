<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaServiceInterface;
use Pulsar\Extension\Cms\Media\MediaVisibility;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function array_map;
use function is_string;
use function max;
use function min;

/**
 * Admin controller for media asset management.
 *
 * Provides CRUD operations for the media library: listing with filters,
 * file upload via multipart form, asset detail with derivatives, and soft delete.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class MediaController
{
    public function __construct(
        private MediaRepositoryInterface $mediaRepository,
        private MediaServiceInterface $mediaService,
        private GateInterface $gate,
    ) {}

    /**
     * List media assets with optional filters.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.media.view');

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 20)));
        $mimeType = is_string($params['mime'] ?? null) ? $params['mime'] : null;
        $visibility = is_string($params['visibility'] ?? null) ? $params['visibility'] : null;

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $result = $this->mediaRepository->listAssets(
            tenantId: $tenantId,
            page: $page,
            perPage: $perPage,
            mimeType: $mimeType,
            visibility: $visibility,
        );

        return Response::json([
            'data' => array_map(static fn(MediaAsset $a) => [
                'id' => $a->id,
                'filename' => $a->filename,
                'mime_type' => $a->mimeType,
                'file_size' => $a->fileSize,
                'width' => $a->width,
                'height' => $a->height,
                'visibility' => $a->visibility->value,
                'uploader_id' => $a->uploaderId,
                'created_at' => $a->createdAt->format('c'),
            ], $result->items),
            'pagination' => $result->metaToArray(),
        ]);
    }

    /**
     * Upload a new media asset via multipart form data.
     */
    public function upload(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.media.upload');

        $uploadedFiles = $request->getUploadedFiles();

        /** @var UploadedFileInterface|null $file */
        $file = $uploadedFiles['file'] ?? null;

        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return Response::json(['error' => 'No valid file uploaded'], 400);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $visibilityValue = is_string($body['visibility'] ?? null) ? $body['visibility'] : 'public';
        $visibility = MediaVisibility::tryFrom($visibilityValue) ?? MediaVisibility::Public;

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        try {
            $asset = $this->mediaService->upload(
                file: $file,
                uploaderId: $identity->id(),
                tenantId: $tenantId,
                visibility: $visibility,
            );

            return Response::json([
                'id' => $asset->id,
                'filename' => $asset->filename,
                'mime_type' => $asset->mimeType,
                'file_size' => $asset->fileSize,
                'visibility' => $asset->visibility->value,
            ], 201);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Show media asset detail with derivatives and translations.
     */
    public function show(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.media.view');

        $asset = $this->mediaRepository->findById($id);

        if ($asset === null) {
            return Response::json(['error' => 'Media asset not found'], 404);
        }

        $derivatives = $this->mediaRepository->findDerivatives($id);
        $translations = $this->mediaRepository->findTranslations($id);

        return Response::json([
            'asset' => [
                'id' => $asset->id,
                'filename' => $asset->filename,
                'storage_path' => $asset->storagePath,
                'disk' => $asset->disk,
                'mime_type' => $asset->mimeType,
                'file_size' => $asset->fileSize,
                'file_hash' => $asset->fileHash,
                'width' => $asset->width,
                'height' => $asset->height,
                'alt_text_default' => $asset->altTextDefault,
                'visibility' => $asset->visibility->value,
                'data_classification' => $asset->dataClassification->value,
                'uploader_id' => $asset->uploaderId,
                'created_at' => $asset->createdAt->format('c'),
                'updated_at' => $asset->updatedAt->format('c'),
            ],
            'derivatives' => array_map(static fn($d) => [
                'id' => $d->id,
                'variant' => $d->variant,
                'format' => $d->format,
                'width' => $d->width,
                'height' => $d->height,
                'file_size' => $d->fileSize,
            ], $derivatives),
            'translations' => array_map(static fn($t) => [
                'locale' => $t->locale,
                'alt_text' => $t->altText,
                'title' => $t->title,
                'caption' => $t->caption,
            ], $translations),
        ]);
    }

    /**
     * Soft-delete a media asset with a reason.
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.media.delete');

        $asset = $this->mediaRepository->findById($id);

        if ($asset === null) {
            return Response::json(['error' => 'Media asset not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : 'Deleted by admin';

        try {
            $this->mediaService->delete($id, $reason);

            return Response::json(['id' => $id, 'status' => 'deleted']);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    private function requireIdentity(ServerRequestInterface $request): IdentityInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            throw new RuntimeException('Authentication required');
        }

        return $identity;
    }

    private function authorize(IdentityInterface $identity, string $permission): void
    {
        if ($this->gate->denies($identity, $permission)) {
            throw new RuntimeException('Permission denied');
        }
    }
}
