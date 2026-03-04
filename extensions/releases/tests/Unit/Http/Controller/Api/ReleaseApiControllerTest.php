<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases\Tests\Unit\Http\Controller\Api;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Releases\BetaSignupRepositoryInterface;
use Pulsar\Extension\Releases\Http\Controller\Api\ReleaseApiController;
use Pulsar\Extension\Releases\Internal\ReleaseService;
use Pulsar\Extension\Releases\Release;
use Pulsar\Extension\Releases\ReleasePlatform;
use Pulsar\Extension\Releases\ReleaseRepositoryInterface;

use function json_decode;

#[CoversClass(ReleaseApiController::class)]
final class ReleaseApiControllerTest extends TestCase
{
    private ReleaseRepositoryInterface&Stub $repository;
    private BetaSignupRepositoryInterface&Stub $betaRepo;
    private ReleaseService $service;
    private ReleaseApiController $controller;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(ReleaseRepositoryInterface::class);
        $this->betaRepo = $this->createStub(BetaSignupRepositoryInterface::class);
        $this->service = new ReleaseService($this->repository, $this->betaRepo);
        $this->controller = new ReleaseApiController($this->service, $this->repository);
    }

    #[Test]
    public function latestVersionReturns422ForInvalidPlatform(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['platform' => 'windows']);

        $response = $this->controller->latestVersion($request);

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('Invalid platform', $body['details']['platform']);
    }

    #[Test]
    public function latestVersionReturns422WhenPlatformMissing(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $response = $this->controller->latestVersion($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function latestVersionReturns404WhenNoRelease(): void
    {
        $this->repository->method('findLatestStable')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['platform' => 'android']);

        $response = $this->controller->latestVersion($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function latestVersionReturns200WithRelease(): void
    {
        $release = Release::create(
            '2.0.0',
            ReleasePlatform::Android,
            new DateTimeImmutable('2026-06-01'),
            'Major update',
            '8.0',
            isStable: true,
        );
        $this->repository->method('findLatestStable')->willReturn($release);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['platform' => 'android']);

        $response = $this->controller->latestVersion($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('2.0.0', $body['data']['version']);
        self::assertSame('android', $body['data']['platform']);
    }

    #[Test]
    public function listReleasesReturns200WithPagination(): void
    {
        $release = Release::create('1.0.0', ReleasePlatform::Ios, new DateTimeImmutable(), 'Notes', '15.0');
        $pagination = new PaginationResult(
            items: [$release],
            total: 1,
            hasMore: false,
            perPage: 20,
            currentPage: 1,
            lastPage: 1,
        );
        $this->repository->method('findAll')->willReturn($pagination);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $response = $this->controller->listReleases($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertArrayHasKey('pagination', $body);
    }

    #[Test]
    public function listReleasesRespectsPaginationParams(): void
    {
        $pagination = new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 5,
            currentPage: 2,
            lastPage: 1,
        );
        $this->repository->method('findAll')->willReturn($pagination);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'page' => '2',
            'per_page' => '5',
            'platform' => 'web',
            'include_beta' => 'true',
        ]);

        $response = $this->controller->listReleases($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function listReleasesHandlesIncludeBetaFalse(): void
    {
        $pagination = new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 20,
            currentPage: 1,
            lastPage: 1,
        );
        $this->repository->method('findAll')->willReturn($pagination);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['include_beta' => 'false']);

        $response = $this->controller->listReleases($request);

        self::assertSame(200, $response->getStatusCode());
    }
}
