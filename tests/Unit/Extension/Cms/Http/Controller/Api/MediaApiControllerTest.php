<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Api;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Cms\Commerce\ApiKey;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Http\Controller\Api\MediaApiController;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaServiceInterface;
use Pulsar\Extension\Cms\Media\MediaVisibility;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

#[CoversClass(MediaApiController::class)]
final class MediaApiControllerTest extends TestCase
{
    private MediaServiceInterface&Stub $mediaService;
    private MediaRepositoryInterface&Stub $mediaRepository;
    private MediaApiController $controller;

    protected function setUp(): void
    {
        $this->mediaService = $this->createStub(MediaServiceInterface::class);
        $this->mediaRepository = $this->createStub(MediaRepositoryInterface::class);
        $this->controller = new MediaApiController($this->mediaService, $this->mediaRepository);
    }

    #[Test]
    public function index_returns_paginated_media(): void
    {
        $now = new DateTimeImmutable();
        $asset = new MediaAsset(
            id: 'media-1',
            tenantId: null,
            uploaderId: 'user-1',
            filename: 'photo.jpg',
            storagePath: 'default/2026/02/ab/photo.jpg',
            disk: 'local',
            mimeType: 'image/jpeg',
            fileSize: 102400,
            fileHash: 'abc123',
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

        $this->mediaRepository->method('listAssets')->willReturn(
            new PaginationResult(items: [$asset], total: 1, hasMore: false, perPage: 20, currentPage: 1),
        );

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/media');

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('X-Total-Count'));

        /** @var array{data: list<array{id: string}>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('media-1', $body['data'][0]['id']);
    }

    #[Test]
    public function show_returns_404_for_missing_asset(): void
    {
        $this->mediaRepository->method('findById')->willReturn(null);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/media/nonexistent');

        $response = $this->controller->show('nonexistent');

        self::assertSame(404, $response->getStatusCode());

        /** @var array{error: string, status: int} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Media asset not found', $body['error']);
    }

    #[Test]
    public function show_returns_asset_details(): void
    {
        $now = new DateTimeImmutable();
        $asset = new MediaAsset(
            id: 'media-1',
            tenantId: null,
            uploaderId: 'user-1',
            filename: 'photo.jpg',
            storagePath: 'default/2026/02/ab/photo.jpg',
            disk: 'local',
            mimeType: 'image/jpeg',
            fileSize: 102400,
            fileHash: 'abc123',
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

        $this->mediaRepository->method('findById')->willReturn($asset);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/media/media-1');

        $response = $this->controller->show('media-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array{data: array{id: string, filename: string}} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('media-1', $body['data']['id']);
        self::assertSame('photo.jpg', $body['data']['filename']);
    }

    #[Test]
    public function upload_returns_422_without_file(): void
    {
        $request = new ServerRequest(method: 'POST', uri: '/api/v1/media');

        $response = $this->controller->upload($request);

        self::assertSame(422, $response->getStatusCode());

        /** @var array{error: string} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('No valid file uploaded', $body['error']);
    }

    #[Test]
    public function delete_returns_401_without_api_key(): void
    {
        // Never look up (or delete) the asset for an unauthenticated caller.
        $this->mediaRepository->method('findById')->willReturn($this->asset('tenant-a'));

        $request = new ServerRequest(method: 'DELETE', uri: '/api/v1/media/media-1');

        $response = $this->controller->delete($request, 'media-1');

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function delete_returns_404_for_missing_asset(): void
    {
        $this->mediaRepository->method('findById')->willReturn(null);

        $request = new ServerRequest(method: 'DELETE', uri: '/api/v1/media/nonexistent')
            ->withAttribute('cms_api_key', $this->apiKey('tenant-a'));

        $response = $this->controller->delete($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function delete_returns_404_for_a_foreign_tenant_asset(): void
    {
        // The asset belongs to tenant-b; a tenant-a key must not delete it.
        $this->mediaRepository->method('findById')->willReturn($this->asset('tenant-b'));

        $request = new ServerRequest(method: 'DELETE', uri: '/api/v1/media/media-1')
            ->withAttribute('cms_api_key', $this->apiKey('tenant-a'));

        $response = $this->controller->delete($request, 'media-1');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function delete_succeeds_for_matching_tenant(): void
    {
        $this->mediaRepository->method('findById')->willReturn($this->asset('tenant-a'));

        $request = new ServerRequest(method: 'DELETE', uri: '/api/v1/media/media-1')
            ->withAttribute('cms_api_key', $this->apiKey('tenant-a'));

        $response = $this->controller->delete($request, 'media-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array{data: array{id: string, status: string}} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('deleted', $body['data']['status']);
    }

    private function apiKey(?string $tenantId): ApiKey
    {
        return new ApiKey(
            id: 'key-1',
            tenantId: $tenantId,
            name: 'test',
            keyHash: 'hash',
            lastUsedAt: null,
            isActive: true,
            createdAt: new DateTimeImmutable(),
            expiresAt: null,
        );
    }

    private function asset(?string $tenantId): MediaAsset
    {
        $now = new DateTimeImmutable();

        return new MediaAsset(
            id: 'media-1',
            tenantId: $tenantId,
            uploaderId: 'user-1',
            filename: 'photo.jpg',
            storagePath: 'default/2026/02/ab/photo.jpg',
            disk: 'local',
            mimeType: 'image/jpeg',
            fileSize: 102400,
            fileHash: 'abc123',
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
}
