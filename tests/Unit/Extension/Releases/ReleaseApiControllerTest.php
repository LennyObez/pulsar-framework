<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Releases;

use DateTimeImmutable;
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
use Pulsar\Http\Message\Response;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class ReleaseApiControllerTest extends TestCase
{
    private ReleaseRepositoryInterface&Stub $releaseRepo;
    private BetaSignupRepositoryInterface&Stub $betaSignupRepo;
    private ReleaseService $service;
    private ReleaseApiController $controller;

    protected function setUp(): void
    {
        $this->releaseRepo = $this->createStub(ReleaseRepositoryInterface::class);
        $this->betaSignupRepo = $this->createStub(BetaSignupRepositoryInterface::class);
        $this->service = new ReleaseService($this->releaseRepo, $this->betaSignupRepo);
        $this->controller = new ReleaseApiController($this->service, $this->releaseRepo);
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
        ReleasePlatform $platform = ReleasePlatform::Android,
        bool $isBeta = false,
        bool $isStable = true,
        ?string $downloadUrl = null,
    ): Release {
        return new Release(
            id: 'rel-001',
            version: $version,
            platform: $platform,
            releaseDate: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
            releaseNotes: 'Bug fixes and improvements for ' . $version,
            minimumOsVersion: '13.0',
            downloadUrl: $downloadUrl,
            isBeta: $isBeta,
            isStable: $isStable,
            createdAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
        );
    }

    // --- latestVersion() tests ---

    #[Test]
    public function latestVersionReturnsReleaseForValidPlatform(): void
    {
        $release = $this->buildRelease();
        $this->releaseRepo->method('findLatestStable')->willReturn($release);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['platform' => 'android']);

        $response = $this->controller->latestVersion($request);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame('2.0.0', $data['version']);
        self::assertSame('android', $data['platform']);
        self::assertTrue($data['is_stable']);
        self::assertFalse($data['is_beta']);
    }

    #[Test]
    public function latestVersionReturns404WhenNoRelease(): void
    {
        $this->releaseRepo->method('findLatestStable')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['platform' => 'ios']);

        $response = $this->controller->latestVersion($request);

        self::assertSame(404, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('No stable release', $error);
    }

    #[Test]
    public function latestVersionReturns422ForInvalidPlatform(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['platform' => 'windows']);

        $response = $this->controller->latestVersion($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Validation failed', $body['error']);
        $details = $body['details'];
        self::assertIsArray($details);
        $platformError = $details['platform'];
        self::assertIsString($platformError);
        self::assertStringContainsString('Invalid platform', $platformError);
    }

    #[Test]
    public function latestVersionReturns422ForMissingPlatform(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $response = $this->controller->latestVersion($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function latestVersionSerializesDownloadUrl(): void
    {
        $release = $this->buildRelease(downloadUrl: 'https://example.com/download/v2.0.0');
        $this->releaseRepo->method('findLatestStable')->willReturn($release);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['platform' => 'android']);

        $response = $this->controller->latestVersion($request);

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame('https://example.com/download/v2.0.0', $data['download_url']);
    }

    #[Test]
    public function latestVersionSerializesNullDownloadUrl(): void
    {
        $release = $this->buildRelease(downloadUrl: null);
        $this->releaseRepo->method('findLatestStable')->willReturn($release);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['platform' => 'web']);

        $response = $this->controller->latestVersion($request);

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertNull($data['download_url']);
    }

    // --- listReleases() tests ---

    #[Test]
    public function listReleasesReturnsPaginatedResults(): void
    {
        $releases = [
            $this->buildRelease('1.0.0'),
            $this->buildRelease('1.1.0'),
        ];

        $result = new PaginationResult(
            items: $releases,
            total: 2,
            hasMore: false,
            perPage: 20,
            currentPage: 1,
            lastPage: 1,
        );

        $this->releaseRepo->method('findAll')->willReturn($result);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $response = $this->controller->listReleases($request);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertIsArray($body['data']);
        self::assertCount(2, $body['data']);
        self::assertIsArray($body['pagination']);
    }

    #[Test]
    public function listReleasesDefaultsToPage1PerPage20(): void
    {
        $result = new PaginationResult(items: [], total: 0, hasMore: false, perPage: 20);
        $this->releaseRepo->method('findAll')->willReturn($result);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $response = $this->controller->listReleases($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function listReleasesClampsPaginationValues(): void
    {
        $result = new PaginationResult(items: [], total: 0, hasMore: false, perPage: 100);
        $this->releaseRepo->method('findAll')->willReturn($result);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'page' => '0',
            'per_page' => '500',
        ]);

        $response = $this->controller->listReleases($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function listReleasesFiltersOnPlatform(): void
    {
        $result = new PaginationResult(items: [], total: 0, hasMore: false, perPage: 20);
        $this->releaseRepo->method('findAll')->willReturn($result);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['platform' => 'ios']);

        $response = $this->controller->listReleases($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function listReleasesIgnoresInvalidPlatformFilter(): void
    {
        $result = new PaginationResult(items: [], total: 0, hasMore: false, perPage: 20);
        $this->releaseRepo->method('findAll')->willReturn($result);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['platform' => 'invalid']);

        $response = $this->controller->listReleases($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function listReleasesHandlesIncludeBetaTrue(): void
    {
        $result = new PaginationResult(items: [], total: 0, hasMore: false, perPage: 20);
        $this->releaseRepo->method('findAll')->willReturn($result);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['include_beta' => 'true']);

        $response = $this->controller->listReleases($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function listReleasesHandlesIncludeBetaFalse(): void
    {
        $result = new PaginationResult(items: [], total: 0, hasMore: false, perPage: 20);
        $this->releaseRepo->method('findAll')->willReturn($result);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['include_beta' => 'false']);

        $response = $this->controller->listReleases($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function serializationEscapesHtmlInVersion(): void
    {
        $release = new Release(
            id: 'rel-xss',
            version: '1.0.0<script>',
            platform: ReleasePlatform::Web,
            releaseDate: new DateTimeImmutable('2026-01-15'),
            releaseNotes: 'Notes with <b>HTML</b>',
            minimumOsVersion: '16',
            downloadUrl: null,
            isBeta: false,
            isStable: true,
            createdAt: new DateTimeImmutable('2026-01-15'),
        );

        $this->releaseRepo->method('findLatestStable')->willReturn($release);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['platform' => 'web']);

        $response = $this->controller->latestVersion($request);

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        $version = $data['version'];
        self::assertIsString($version);
        self::assertStringNotContainsString('<script>', $version);
        $notes = $data['release_notes'];
        self::assertIsString($notes);
        self::assertStringNotContainsString('<b>', $notes);
    }
}
