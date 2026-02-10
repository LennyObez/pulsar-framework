<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Security;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Http\Controller\MediaController;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\MediaDerivative;
use Pulsar\Extension\Cms\Media\MediaDiskInterface;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaVisibility;

/**
 * Security tests verifying private media access control.
 *
 * Verifies S13: Private media assets are denied to unauthenticated users
 * while public assets remain freely accessible.
 */
#[CoversClass(MediaController::class)]
final class PrivateMediaAccessTest extends TestCase
{
    private MediaController $controller;
    private MediaRepositoryInterface&Stub $repository;
    private MediaDiskInterface&Stub $disk;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(MediaRepositoryInterface::class);
        $this->disk = $this->createStub(MediaDiskInterface::class);
        $this->controller = new MediaController($this->repository, $this->disk);
    }

    // -- serveOriginal: private media ----------------------------------------

    #[Test]
    public function privateOriginalDeniedWithoutIdentity(): void
    {
        $asset = $this->createAsset(MediaVisibility::Private);
        $this->repository->method('findByHash')->willReturn($asset);

        $request = $this->createRequest(identity: null);
        $response = $this->controller->serveOriginal($request, 'abc123', 'test.jpg');

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function privateOriginalDeniedForUnauthenticatedIdentity(): void
    {
        $asset = $this->createAsset(MediaVisibility::Private);
        $this->repository->method('findByHash')->willReturn($asset);

        $identity = $this->createIdentity(authenticated: false);
        $request = $this->createRequest(identity: $identity);
        $response = $this->controller->serveOriginal($request, 'abc123', 'test.jpg');

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function privateOriginalServedForAuthenticatedIdentity(): void
    {
        $asset = $this->createAsset(MediaVisibility::Private);
        $this->repository->method('findByHash')->willReturn($asset);
        $this->disk->method('read')->willReturn('file-contents');

        $identity = $this->createIdentity(authenticated: true);
        $request = $this->createRequest(identity: $identity);
        $response = $this->controller->serveOriginal($request, 'abc123', 'test.jpg');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function publicOriginalServedWithoutIdentity(): void
    {
        $asset = $this->createAsset(MediaVisibility::Public);
        $this->repository->method('findByHash')->willReturn($asset);
        $this->disk->method('read')->willReturn('file-contents');

        $request = $this->createRequest(identity: null);
        $response = $this->controller->serveOriginal($request, 'abc123', 'test.jpg');

        self::assertSame(200, $response->getStatusCode());
    }

    // -- serve (derivatives): private media -----------------------------------

    #[Test]
    public function privateDerivativeDeniedWithoutIdentity(): void
    {
        $asset = $this->createAsset(MediaVisibility::Private);
        $this->repository->method('findByHash')->willReturn($asset);

        $request = $this->createRequest(identity: null);
        $response = $this->controller->serve($request, 'thumb', 'abc123', 'test', 'webp');

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function privateDerivativeServedForAuthenticatedIdentity(): void
    {
        $asset = $this->createAsset(MediaVisibility::Private);
        $derivative = new MediaDerivative(
            id: 'deriv-1',
            mediaAssetId: $asset->id,
            variant: 'thumb',
            format: 'webp',
            storagePath: 'derivatives/thumb.webp',
            fileSize: 1024,
            width: 150,
            height: 150,
            fileHash: 'deadbeef',
            createdAt: new DateTimeImmutable(),
        );

        $this->repository->method('findByHash')->willReturn($asset);
        $this->repository->method('findDerivatives')->willReturn([$derivative]);
        $this->disk->method('read')->willReturn('derivative-contents');

        $identity = $this->createIdentity(authenticated: true);
        $request = $this->createRequest(identity: $identity);
        $response = $this->controller->serve($request, 'thumb', 'abc123', 'test', 'webp');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function publicDerivativeHasPublicCacheHeaders(): void
    {
        $asset = $this->createAsset(MediaVisibility::Public);
        $derivative = new MediaDerivative(
            id: 'deriv-1',
            mediaAssetId: $asset->id,
            variant: 'thumb',
            format: 'webp',
            storagePath: 'derivatives/thumb.webp',
            fileSize: 1024,
            width: 150,
            height: 150,
            fileHash: 'deadbeef',
            createdAt: new DateTimeImmutable(),
        );

        $this->repository->method('findByHash')->willReturn($asset);
        $this->repository->method('findDerivatives')->willReturn([$derivative]);
        $this->disk->method('read')->willReturn('derivative-contents');

        $request = $this->createRequest(identity: null);
        $response = $this->controller->serve($request, 'thumb', 'abc123', 'test', 'webp');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('public', $response->getHeaderLine('Cache-Control'));
    }

    // -- Deleted assets always return 404 ------------------------------------

    #[Test]
    public function deletedPrivateAssetReturns404Not403(): void
    {
        $asset = $this->createAsset(MediaVisibility::Private, deleted: true);
        $this->repository->method('findByHash')->willReturn($asset);

        $request = $this->createRequest(identity: null);
        $response = $this->controller->serveOriginal($request, 'abc123', 'test.jpg');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function nonexistentAssetReturns404(): void
    {
        $this->repository->method('findByHash')->willReturn(null);

        $request = $this->createRequest(identity: null);
        $response = $this->controller->serveOriginal($request, 'abc123', 'test.jpg');

        self::assertSame(404, $response->getStatusCode());
    }

    // -- Helpers --------------------------------------------------------------

    private function createAsset(MediaVisibility $visibility, bool $deleted = false): MediaAsset
    {
        $now = new DateTimeImmutable();

        return new MediaAsset(
            id: 'asset-1',
            tenantId: null,
            uploaderId: 'user-1',
            filename: 'test.jpg',
            storagePath: 'uploads/test.jpg',
            disk: 'local',
            mimeType: 'image/jpeg',
            fileSize: 4096,
            fileHash: 'abc123',
            width: 800,
            height: 600,
            exifData: null,
            altTextDefault: null,
            visibility: $visibility,
            dataClassification: DataClassification::Public,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: $deleted ? $now : null,
        );
    }

    private function createRequest(?IdentityInterface $identity): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(static function (string $name) use ($identity): mixed {
                if ($name === 'identity') {
                    return $identity;
                }

                return null;
            });

        return $request;
    }

    private function createIdentity(bool $authenticated): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn($authenticated);
        $identity->method('id')->willReturn('user-1');
        $identity->method('displayName')->willReturn('Test User');
        $identity->method('roles')->willReturn([]);
        $identity->method('hasRole')->willReturn(false);
        $identity->method('twoFactorStatus')->willReturn(TwoFactorStatus::Disabled);
        $identity->method('attributes')->willReturn([]);
        $identity->method('attribute')->willReturn(null);

        return $identity;
    }
}
