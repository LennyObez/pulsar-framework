<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Releases;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Releases\BetaSignup;
use Pulsar\Extension\Releases\BetaSignupRepositoryInterface;
use Pulsar\Extension\Releases\DeviceType;
use Pulsar\Extension\Releases\Http\Controller\Admin\ReleaseController;
use Pulsar\Extension\Releases\Internal\ReleaseService;
use Pulsar\Extension\Releases\Release;
use Pulsar\Extension\Releases\ReleasePlatform;
use Pulsar\Extension\Releases\ReleaseRepositoryInterface;
use Pulsar\Http\Message\Response;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class AdminReleaseControllerTest extends TestCase
{
    private ReleaseRepositoryInterface&Stub $releaseRepo;
    private BetaSignupRepositoryInterface&Stub $betaSignupRepo;
    private ReleaseController $controller;

    protected function setUp(): void
    {
        $this->releaseRepo = $this->createStub(ReleaseRepositoryInterface::class);
        $this->betaSignupRepo = $this->createStub(BetaSignupRepositoryInterface::class);
        $service = new ReleaseService($this->releaseRepo, $this->betaSignupRepo);
        $this->controller = new ReleaseController($service, $this->releaseRepo, $this->betaSignupRepo);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonResponse(Response $response): array
    {
        $decoded = json_decode($response->getBody()->__toString(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> */
        return $decoded;
    }

    private function buildRelease(
        string $version = '2.0.0',
        bool $isStable = true,
        bool $isBeta = false,
    ): Release {
        return new Release(
            id: 'rel-001',
            version: $version,
            platform: ReleasePlatform::Android,
            releaseDate: new DateTimeImmutable('2026-01-15'),
            releaseNotes: 'Release notes for ' . $version,
            minimumOsVersion: '13.0',
            downloadUrl: null,
            isBeta: $isBeta,
            isStable: $isStable,
            createdAt: new DateTimeImmutable('2026-01-15'),
        );
    }

    // --- index() tests ---

    #[Test]
    public function indexReturnsPaginatedReleases(): void
    {
        $releases = [$this->buildRelease('1.0.0'), $this->buildRelease('1.1.0')];
        $result = new PaginationResult(items: $releases, total: 2, hasMore: false, perPage: 20);
        $this->releaseRepo->method('findAll')->willReturn($result);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertCount(2, $data);
        self::assertIsArray($body['pagination']);
    }

    #[Test]
    public function indexClampsPageAndPerPage(): void
    {
        $result = new PaginationResult(items: [], total: 0, hasMore: false, perPage: 100);
        $this->releaseRepo->method('findAll')->willReturn($result);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['page' => '-5', 'per_page' => '999']);

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function indexFiltersByPlatform(): void
    {
        $result = new PaginationResult(items: [], total: 0, hasMore: false, perPage: 20);
        $this->releaseRepo->method('findAll')->willReturn($result);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['platform' => 'ios']);

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function indexFiltersByIncludeBeta(): void
    {
        $result = new PaginationResult(items: [], total: 0, hasMore: false, perPage: 20);
        $this->releaseRepo->method('findAll')->willReturn($result);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['include_beta' => 'true']);

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
    }

    // --- create() tests ---

    #[Test]
    public function createReturnsFieldMetadata(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $this->controller->create();

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertIsArray($data['platforms']);
        self::assertContains('android', $data['platforms']);
        self::assertContains('ios', $data['platforms']);
        self::assertContains('web', $data['platforms']);
        self::assertIsArray($data['fields']);
        self::assertArrayHasKey('version', $data['fields']);
        self::assertArrayHasKey('platform', $data['fields']);
        self::assertArrayHasKey('release_date', $data['fields']);
        self::assertArrayHasKey('release_notes', $data['fields']);
    }

    // --- store() tests ---

    #[Test]
    public function storeCreatesNewRelease(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'version' => '3.0.0',
            'platform' => 'android',
            'release_date' => '2026-06-01',
            'release_notes' => 'Major release with new features.',
            'minimum_os_version' => '14',
            'download_url' => 'https://example.com/download',
            'is_beta' => false,
            'is_stable' => true,
        ]);

        $response = $this->controller->store($request);

        self::assertSame(201, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame('3.0.0', $data['version']);
        self::assertSame('android', $data['platform']);
        self::assertTrue($data['is_stable']);
    }

    #[Test]
    public function storeReturns422ForInvalidPlatform(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'version' => '1.0.0',
            'platform' => 'invalid',
            'release_notes' => 'Some notes',
            'minimum_os_version' => '13',
        ]);

        $response = $this->controller->store($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Validation failed', $body['error']);
    }

    #[Test]
    public function storeReturns422ForEmptyVersion(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'version' => '',
            'platform' => 'ios',
            'release_notes' => 'Some notes',
            'minimum_os_version' => '16',
        ]);

        $response = $this->controller->store($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function storeReturns422ForEmptyReleaseNotes(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'version' => '1.0.0',
            'platform' => 'web',
            'release_notes' => '',
            'minimum_os_version' => 'any',
        ]);

        $response = $this->controller->store($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function storeHandlesIsBetaAsStringTrue(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'version' => '2.0.0-beta',
            'platform' => 'android',
            'release_notes' => 'Beta release with experimental features.',
            'minimum_os_version' => '13',
            'is_beta' => 'true',
        ]);

        $response = $this->controller->store($request);

        self::assertSame(201, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertTrue($data['is_beta']);
    }

    #[Test]
    public function storeHandlesIsStableAsInteger1(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'version' => '2.0.0',
            'platform' => 'ios',
            'release_notes' => 'Stable release for iOS.',
            'minimum_os_version' => '16',
            'is_stable' => 1,
        ]);

        $response = $this->controller->store($request);

        self::assertSame(201, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertTrue($data['is_stable']);
    }

    #[Test]
    public function storeWithEmptyDownloadUrlSetsNull(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'version' => '1.0.0',
            'platform' => 'web',
            'release_notes' => 'Release with no download URL.',
            'minimum_os_version' => 'any',
            'download_url' => '',
        ]);

        $response = $this->controller->store($request);

        self::assertSame(201, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertNull($data['download_url']);
    }

    // --- edit() tests ---

    #[Test]
    public function editReturnsReleaseById(): void
    {
        $release = $this->buildRelease();
        $this->releaseRepo->method('findById')->willReturn($release);

        $request = $this->createStub(ServerRequestInterface::class);

        $response = $this->controller->edit('rel-001');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame('rel-001', $data['id']);
    }

    #[Test]
    public function editReturns404ForNonexistentRelease(): void
    {
        $this->releaseRepo->method('findById')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);

        $response = $this->controller->edit('nonexistent');

        self::assertSame(404, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Release not found', $body['error']);
    }

    // --- update() tests ---

    #[Test]
    public function updateMarksReleaseAsStable(): void
    {
        $release = $this->buildRelease(isStable: false);
        $this->releaseRepo->method('findById')->willReturn($release);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['is_stable' => true]);

        $response = $this->controller->update($request, 'rel-001');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertTrue($data['is_stable']);
    }

    #[Test]
    public function updateReturns404WhenMarkStableOnNonexistentRelease(): void
    {
        $this->releaseRepo->method('findById')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['is_stable' => true]);

        $response = $this->controller->update($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function updateWithoutStableFlagReturnsExistingRelease(): void
    {
        $release = $this->buildRelease();
        $this->releaseRepo->method('findById')->willReturn($release);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([]);

        $response = $this->controller->update($request, 'rel-001');

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function updateWithoutStableFlagReturns404WhenNotFound(): void
    {
        $this->releaseRepo->method('findById')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([]);

        $response = $this->controller->update($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function updateHandlesIsStableAsStringTrue(): void
    {
        $release = $this->buildRelease(isStable: false);
        $this->releaseRepo->method('findById')->willReturn($release);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['is_stable' => 'true']);

        $response = $this->controller->update($request, 'rel-001');

        self::assertSame(200, $response->getStatusCode());
    }

    // --- betaSignups() tests ---

    #[Test]
    public function betaSignupsReturnsPaginatedResults(): void
    {
        $signup = new BetaSignup(
            id: 'bs-001',
            email: 'beta@example.com',
            deviceType: DeviceType::Android,
            cameraBrands: ['Canon'],
            signedUpAt: new DateTimeImmutable('2026-03-01'),
            invitedAt: null,
            inviteTokenHash: null,
        );

        $result = new PaginationResult(items: [$signup], total: 1, hasMore: false, perPage: 20);
        $this->betaSignupRepo->method('findAll')->willReturn($result);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $response = $this->controller->betaSignups($request);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertCount(1, $data);
        $first = $data[0];
        self::assertIsArray($first);
        self::assertSame('bs-001', $first['id']);
        self::assertSame('beta@example.com', $first['email']);
        self::assertSame('android', $first['device_type']);
        self::assertSame(['Canon'], $first['camera_brands']);
        self::assertNull($first['invited_at']);
    }

    #[Test]
    public function betaSignupsShowsInvitedAtWhenPresent(): void
    {
        $invitedAt = new DateTimeImmutable('2026-03-10T12:00:00+00:00');
        $signup = new BetaSignup(
            id: 'bs-002',
            email: 'invited@example.com',
            deviceType: DeviceType::Ios,
            cameraBrands: [],
            signedUpAt: new DateTimeImmutable('2026-03-01'),
            invitedAt: $invitedAt,
            inviteTokenHash: 'hash-token',
        );

        $result = new PaginationResult(items: [$signup], total: 1, hasMore: false, perPage: 20);
        $this->betaSignupRepo->method('findAll')->willReturn($result);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $response = $this->controller->betaSignups($request);

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        $first = $data[0];
        self::assertIsArray($first);
        self::assertSame($invitedAt->format('c'), $first['invited_at']);
    }

    #[Test]
    public function betaSignupsClampsPagination(): void
    {
        $result = new PaginationResult(items: [], total: 0, hasMore: false, perPage: 100);
        $this->betaSignupRepo->method('findAll')->willReturn($result);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['page' => '0', 'per_page' => '500']);

        $response = $this->controller->betaSignups($request);

        self::assertSame(200, $response->getStatusCode());
    }
}
