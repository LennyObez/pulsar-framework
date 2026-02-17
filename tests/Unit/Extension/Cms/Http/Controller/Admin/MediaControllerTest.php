<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Http\Controller\Admin\MediaController;
use Pulsar\Extension\Cms\Media\ImageVariant;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\MediaAssetTranslation;
use Pulsar\Extension\Cms\Media\MediaDerivative;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaServiceInterface;
use Pulsar\Extension\Cms\Media\MediaVisibility;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(MediaController::class)]
final class MediaControllerTest extends TestCase
{
    #[Test]
    public function index_returns_paginated_media_assets(): void
    {
        $asset = $this->createAsset('asset-1', 'photo.jpg', 'image/jpeg');

        $repo = $this->createStub(MediaRepositoryInterface::class);
        $repo->method('listAssets')->willReturn(new PaginationResult(
            items: [$asset],
            total: 1,
            hasMore: false,
            perPage: 20,
        ));

        $service = $this->createStub(MediaServiceInterface::class);
        $controller = new MediaController(mediaRepository: $repo, mediaService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $items */
        $items = $body['items'];
        self::assertCount(1, $items);
        self::assertSame('asset-1', $items[0]['id']);
        self::assertSame('photo.jpg', $items[0]['filename']);
        self::assertSame('image/jpeg', $items[0]['mime_type']);
        self::assertSame('public', $items[0]['visibility']);
    }

    #[Test]
    public function upload_returns_201_with_valid_file(): void
    {
        $asset = $this->createAsset('asset-new', 'upload.png', 'image/png');

        $repo = $this->createStub(MediaRepositoryInterface::class);
        $service = $this->createStub(MediaServiceInterface::class);
        $service->method('upload')->willReturn($asset);

        $controller = new MediaController(mediaRepository: $repo, mediaService: $service);

        $file = $this->createStub(UploadedFileInterface::class);
        $file->method('getError')->willReturn(UPLOAD_ERR_OK);

        $request = $this->createAuthenticatedRequest(
            uploadedFiles: ['file' => $file],
            parsedBody: ['visibility' => 'private'],
        );

        $response = $controller->upload($request);

        self::assertSame(201, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('asset-new', $body['id']);
        self::assertSame('upload.png', $body['filename']);
    }

    #[Test]
    public function upload_returns_400_without_file(): void
    {
        $repo = $this->createStub(MediaRepositoryInterface::class);
        $service = $this->createStub(MediaServiceInterface::class);
        $controller = new MediaController(mediaRepository: $repo, mediaService: $service);
        $request = $this->createAuthenticatedRequest(uploadedFiles: []);

        $response = $controller->upload($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function upload_returns_400_with_upload_error(): void
    {
        $repo = $this->createStub(MediaRepositoryInterface::class);
        $service = $this->createStub(MediaServiceInterface::class);
        $controller = new MediaController(mediaRepository: $repo, mediaService: $service);

        $file = $this->createStub(UploadedFileInterface::class);
        $file->method('getError')->willReturn(UPLOAD_ERR_INI_SIZE);

        $request = $this->createAuthenticatedRequest(uploadedFiles: ['file' => $file]);

        $response = $controller->upload($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function upload_returns_422_on_service_exception(): void
    {
        $repo = $this->createStub(MediaRepositoryInterface::class);
        $service = $this->createStub(MediaServiceInterface::class);
        $service->method('upload')->willThrowException(new CmsException('Invalid file type'));

        $controller = new MediaController(mediaRepository: $repo, mediaService: $service);

        $file = $this->createStub(UploadedFileInterface::class);
        $file->method('getError')->willReturn(UPLOAD_ERR_OK);

        $request = $this->createAuthenticatedRequest(uploadedFiles: ['file' => $file]);

        $response = $controller->upload($request);

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Invalid file type', $body['error']);
    }

    #[Test]
    public function show_returns_asset_with_derivatives_and_variants(): void
    {
        $asset = $this->createAsset('asset-1', 'photo.jpg', 'image/jpeg');
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');

        $derivative = new MediaDerivative(
            id: 'deriv-1',
            mediaAssetId: 'asset-1',
            variant: 'thumbnail',
            format: 'webp',
            storagePath: 'media/asset-1/thumb.webp',
            fileSize: 5000,
            width: 150,
            height: 150,
            fileHash: 'deriv_hash',
            createdAt: $now,
        );

        $variant = new ImageVariant(
            path: 'media/asset-1/medium.webp',
            width: 800,
            height: 600,
            format: 'webp',
            sizeBytes: 45000,
        );

        $translation = new MediaAssetTranslation(
            mediaAssetId: 'asset-1',
            locale: 'en',
            altText: 'A beautiful photo',
            caption: 'Photo caption',
            title: 'Photo title',
        );

        $repo = $this->createStub(MediaRepositoryInterface::class);
        $repo->method('findById')->willReturn($asset);
        $repo->method('findDerivatives')->willReturn([$derivative]);
        $repo->method('findTranslations')->willReturn([$translation]);

        $service = $this->createStub(MediaServiceInterface::class);
        $service->method('getVariants')->willReturn([$variant]);

        $controller = new MediaController(mediaRepository: $repo, mediaService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'asset-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $assetData */
        $assetData = $body['asset'];
        self::assertSame('asset-1', $assetData['id']);
        self::assertSame('photo.jpg', $assetData['filename']);
        self::assertSame('public', $assetData['visibility']);

        /** @var list<array<string, mixed>> $derivativesData */
        $derivativesData = $body['derivatives'];
        self::assertCount(1, $derivativesData);
        self::assertSame('thumbnail', $derivativesData[0]['variant']);
        self::assertSame('webp', $derivativesData[0]['format']);

        /** @var list<array<string, mixed>> $variantsData */
        $variantsData = $body['variants'];
        self::assertCount(1, $variantsData);
        self::assertSame(800, $variantsData[0]['width']);

        /** @var list<array<string, mixed>> $translationsData */
        $translationsData = $body['translations'];
        self::assertCount(1, $translationsData);
        self::assertSame('A beautiful photo', $translationsData[0]['alt_text']);
    }

    #[Test]
    public function show_returns_404_when_asset_not_found(): void
    {
        $repo = $this->createStub(MediaRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $service = $this->createStub(MediaServiceInterface::class);
        $controller = new MediaController(mediaRepository: $repo, mediaService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function delete_returns_success_with_valid_reason(): void
    {
        $asset = $this->createAsset('asset-1', 'photo.jpg', 'image/jpeg');

        $repo = $this->createStub(MediaRepositoryInterface::class);
        $repo->method('findById')->willReturn($asset);

        $service = $this->createStub(MediaServiceInterface::class);
        $controller = new MediaController(mediaRepository: $repo, mediaService: $service);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['reason' => 'No longer needed for this project'],
        );

        $response = $controller->delete($request, 'asset-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('deleted', $body['status']);
    }

    #[Test]
    public function delete_returns_400_when_reason_too_short(): void
    {
        $asset = $this->createAsset('asset-1', 'photo.jpg', 'image/jpeg');

        $repo = $this->createStub(MediaRepositoryInterface::class);
        $repo->method('findById')->willReturn($asset);

        $service = $this->createStub(MediaServiceInterface::class);
        $controller = new MediaController(mediaRepository: $repo, mediaService: $service);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['reason' => 'short'],
        );

        $response = $controller->delete($request, 'asset-1');

        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('10 characters', $body['error']);
    }

    #[Test]
    public function delete_returns_404_when_asset_not_found(): void
    {
        $repo = $this->createStub(MediaRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $service = $this->createStub(MediaServiceInterface::class);
        $controller = new MediaController(mediaRepository: $repo, mediaService: $service);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['reason' => 'Asset is no longer needed'],
        );

        $response = $controller->delete($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function delete_returns_422_on_service_exception(): void
    {
        $asset = $this->createAsset('asset-1', 'photo.jpg', 'image/jpeg');

        $repo = $this->createStub(MediaRepositoryInterface::class);
        $repo->method('findById')->willReturn($asset);

        $service = $this->createStub(MediaServiceInterface::class);
        $service->method('delete')->willThrowException(new CmsException('Asset in use'));

        $controller = new MediaController(mediaRepository: $repo, mediaService: $service);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['reason' => 'Need to remove this obsolete asset'],
        );

        $response = $controller->delete($request, 'asset-1');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function variants_returns_image_variants(): void
    {
        $variant = new ImageVariant(
            path: 'media/asset-1/thumb.webp',
            width: 150,
            height: 150,
            format: 'webp',
            sizeBytes: 5000,
        );

        $repo = $this->createStub(MediaRepositoryInterface::class);
        $service = $this->createStub(MediaServiceInterface::class);
        $service->method('getVariants')->willReturn([$variant]);

        $controller = new MediaController(mediaRepository: $repo, mediaService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->variants($request, 'asset-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('asset-1', $body['media_id']);

        /** @var list<array<string, mixed>> $variantsData */
        $variantsData = $body['variants'];
        self::assertCount(1, $variantsData);
        self::assertSame(150, $variantsData[0]['width']);
        self::assertSame('webp', $variantsData[0]['format']);
    }

    #[Test]
    public function variants_returns_404_on_exception(): void
    {
        $repo = $this->createStub(MediaRepositoryInterface::class);
        $service = $this->createStub(MediaServiceInterface::class);
        $service->method('getVariants')->willThrowException(new CmsException('Asset not found'));

        $controller = new MediaController(mediaRepository: $repo, mediaService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->variants($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $repo = $this->createStub(MediaRepositoryInterface::class);
        $service = $this->createStub(MediaServiceInterface::class);
        $controller = new MediaController(mediaRepository: $repo, mediaService: $service);

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function index_throws_when_authorization_denied(): void
    {
        $repo = $this->createStub(MediaRepositoryInterface::class);
        $service = $this->createStub(MediaServiceInterface::class);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new MediaController(
            mediaRepository: $repo,
            mediaService: $service,
            gate: $gate,
        );
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->index($request);
    }

    private function createAsset(string $id, string $filename, string $mimeType): MediaAsset
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');

        return new MediaAsset(
            id: $id,
            tenantId: null,
            uploaderId: 'admin-1',
            filename: $filename,
            storagePath: 'media/' . $id . '/' . $filename,
            disk: 'local',
            mimeType: $mimeType,
            fileSize: 102400,
            fileHash: 'sha256_hash_' . $id,
            width: 1920,
            height: 1080,
            exifData: null,
            altTextDefault: null,
            visibility: MediaVisibility::Public,
            dataClassification: DataClassification::Public,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     * @param array<string, mixed>|null $uploadedFiles
     */
    private function createAuthenticatedRequest(
        bool $stepUp = false,
        ?array $parsedBody = null,
        ?array $uploadedFiles = null,
    ): ServerRequestInterface {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/media');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                'step_up_verified' => $stepUp,
                default => $default,
            },
        );

        if ($parsedBody !== null) {
            $request->method('getParsedBody')->willReturn($parsedBody);
        }

        if ($uploadedFiles !== null) {
            $request->method('getUploadedFiles')->willReturn($uploadedFiles);
        }

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/media');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
