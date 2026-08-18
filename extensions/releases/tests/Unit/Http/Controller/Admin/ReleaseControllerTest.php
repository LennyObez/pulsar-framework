<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases\Tests\Unit\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
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

use function json_decode;

#[CoversClass(ReleaseController::class)]
final class ReleaseControllerTest extends TestCase
{
    private ReleaseRepositoryInterface&Stub $releaseRepo;
    private BetaSignupRepositoryInterface&Stub $betaRepo;
    private ReleaseService $service;
    private ReleaseController $controller;

    protected function setUp(): void
    {
        $this->releaseRepo = $this->createStub(ReleaseRepositoryInterface::class);
        $this->betaRepo = $this->createStub(BetaSignupRepositoryInterface::class);
        $this->service = new ReleaseService($this->releaseRepo, $this->betaRepo);
        $this->controller = new ReleaseController($this->service, $this->releaseRepo, $this->betaRepo);
    }

    #[Test]
    public function indexReturnsReleasesWithPagination(): void
    {
        $release = Release::create('1.0.0', ReleasePlatform::Android, new DateTimeImmutable(), 'Notes', '8.0');
        $pagination = new PaginationResult(
            items: [$release],
            total: 1,
            hasMore: false,
            perPage: 20,
            currentPage: 1,
            lastPage: 1,
        );
        $this->releaseRepo->method('findAll')->willReturn($pagination);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertArrayHasKey('pagination', $body);
    }

    #[Test]
    public function indexRespectsPaginationAndFilters(): void
    {
        $pagination = new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 10,
            currentPage: 2,
            lastPage: 1,
        );
        $this->releaseRepo->method('findAll')->willReturn($pagination);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'page' => '2',
            'per_page' => '10',
            'platform' => 'ios',
            'include_beta' => 'true',
        ]);

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function createReturnsFieldMetadata(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $this->controller->create($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('platforms', $body['data']);
        self::assertArrayHasKey('fields', $body['data']);
        self::assertContains('android', $body['data']['platforms']);
    }

    #[Test]
    public function storeReturns422ForInvalidPlatform(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'version' => '1.0',
            'platform' => 'invalid',
        ]);

        $response = $this->controller->store($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function storeReturns201OnSuccess(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'version' => '2.0.0',
            'platform' => 'web',
            'release_date' => '2026-06-01',
            'release_notes' => 'New features',
            'minimum_os_version' => '1.0',
            'is_stable' => true,
        ]);

        $response = $this->controller->store($request);

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function storeReturns422WhenVersionTooLong(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'version' => str_repeat('v', 50),
            'platform' => 'android',
            'release_notes' => 'Notes',
            'minimum_os_version' => '8.0',
        ]);

        $response = $this->controller->store($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function editReturns404WhenNotFound(): void
    {
        $this->releaseRepo->method('findById')->willReturn(null);

        $response = $this->controller->edit('nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function editReturnsReleaseData(): void
    {
        $release = Release::create('1.0.0', ReleasePlatform::Ios, new DateTimeImmutable(), 'Notes', '15.0');
        $this->releaseRepo->method('findById')->willReturn($release);

        $response = $this->controller->edit($release->id);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('1.0.0', $body['data']['version']);
    }

    #[Test]
    public function updateMarksStable(): void
    {
        $release = Release::create('1.0.0-beta', ReleasePlatform::Android, new DateTimeImmutable(), 'Beta', '8.0');
        $this->releaseRepo->method('findById')->willReturn($release);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['is_stable' => true]);

        $response = $this->controller->update($request, $release->id);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['data']['is_stable']);
    }

    #[Test]
    public function updateReturns404WhenMarkStableNotFound(): void
    {
        $this->releaseRepo->method('findById')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['is_stable' => 'true']);

        $response = $this->controller->update($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function updateWithoutStableFlagReturnsRelease(): void
    {
        $release = Release::create('1.0.0', ReleasePlatform::Web, new DateTimeImmutable(), 'Notes', '1.0');
        $this->releaseRepo->method('findById')->willReturn($release);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([]);

        $response = $this->controller->update($request, $release->id);

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
    public function betaSignupsReturnsListWithPagination(): void
    {
        $signup = BetaSignup::create('test@example.com', DeviceType::Android, ['Canon']);
        $pagination = new PaginationResult(
            items: [$signup],
            total: 1,
            hasMore: false,
            perPage: 20,
            currentPage: 1,
            lastPage: 1,
        );
        $this->betaRepo->method('findAll')->willReturn($pagination);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $response = $this->controller->betaSignups($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('test@example.com', $body['data'][0]['email']);
    }
}
