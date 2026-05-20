<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Media\ImageVariant;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaServiceInterface;
use Pulsar\Extension\Cms\Media\MediaVisibility;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_int;
use function is_string;
use function max;
use function min;
use function strlen;

/**
 * Admin controller for media asset management.
 *
 * Provides CRUD operations for the media library: listing with filters,
 * file upload via multipart form, asset detail with derivatives, and soft delete.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class MediaController extends AbstractAdminController
{
    public function __construct(
        private MediaRepositoryInterface $mediaRepository,
        private MediaServiceInterface $mediaService,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    /**
     * List media assets with optional filters.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.media.view');

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
        /** @var mixed $rawVisibility */
        $rawVisibility = $params['visibility'] ?? null;
        $visibility = is_string($rawVisibility) ? $rawVisibility : null;

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $result = $this->mediaRepository->listAssets(
            tenantId: $tenantId,
            page: $page,
            perPage: $perPage,
            mimeType: $mimeType,
            visibility: $visibility,
        );

        $data = [
            'items' => array_map(static fn(MediaAsset $a) => [
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
        ];

        return $this->respondWithView($request, 'admin.media.index', $data);
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

        /** @var mixed $rawVisibility */
        $rawVisibility = $body['visibility'] ?? null;
        $visibilityValue = is_string($rawVisibility) ? $rawVisibility : 'public';
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
        $variants = $this->mediaService->getVariants($id);

        $data = [
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
            'variants' => array_map(static fn(ImageVariant $v) => [
                'path' => $v->path,
                'width' => $v->width,
                'height' => $v->height,
                'format' => $v->format,
                'size_bytes' => $v->sizeBytes,
            ], $variants),
            'translations' => array_map(static fn($t) => [
                'locale' => $t->locale,
                'alt_text' => $t->altText,
                'title' => $t->title,
                'caption' => $t->caption,
            ], $translations),
        ];

        return $this->respondWithView($request, 'admin.media.show', $data);
    }

    /**
     * Soft-delete a media asset with a mandatory reason.
     *
     * Requires step-up authentication.
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.media.delete');
        $this->requireStepUp($request);

        $asset = $this->mediaRepository->findById($id);

        if ($asset === null) {
            return Response::json(['error' => 'Media asset not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        /** @var mixed $rawReason */
        $rawReason = $body['reason'] ?? null;
        $reason = is_string($rawReason) ? $rawReason : '';

        if (strlen($reason) < 10) {
            return Response::json([
                'error' => 'A reason of at least 10 characters is required for media deletion',
            ], 400);
        }

        try {
            $this->mediaService->delete($id, $reason);

            return Response::json(['id' => $id, 'status' => 'deleted']);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * List image variants for a media asset.
     */
    public function variants(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.media.view');

        try {
            $variants = $this->mediaService->getVariants($id);

            return Response::json([
                'media_id' => $id,
                'variants' => array_map(static fn(ImageVariant $v) => [
                    'path' => $v->path,
                    'width' => $v->width,
                    'height' => $v->height,
                    'format' => $v->format,
                    'size_bytes' => $v->sizeBytes,
                ], $variants),
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }
    }
}
