<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Releases;

use DateTimeImmutable;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Releases\BetaSignup;
use Pulsar\Extension\Releases\BetaSignupRepositoryInterface;
use Pulsar\Extension\Releases\DeviceType;
use Pulsar\Extension\Releases\Internal\ReleaseService;
use Pulsar\Extension\Releases\Release;
use Pulsar\Extension\Releases\ReleasePlatform;
use Pulsar\Extension\Releases\ReleaseRepositoryInterface;

final class ReleaseServiceTest extends TestCase
{
    private ReleaseRepositoryInterface&Stub $releaseRepo;
    private BetaSignupRepositoryInterface&Stub $betaSignupRepo;
    private ReleaseService $service;

    protected function setUp(): void
    {
        $this->releaseRepo = $this->createStub(ReleaseRepositoryInterface::class);
        $this->betaSignupRepo = $this->createStub(BetaSignupRepositoryInterface::class);

        $this->service = new ReleaseService($this->releaseRepo, $this->betaSignupRepo);
    }

    #[Test]
    public function getLatestVersionDelegatesToRepository(): void
    {
        $release = $this->buildRelease('2.0.0', isStable: true);
        $this->releaseRepo->method('findLatestStable')->willReturn($release);

        $result = $this->service->getLatestVersion(ReleasePlatform::Android);

        self::assertNotNull($result);
        self::assertSame('2.0.0', $result->version);
        self::assertTrue($result->isStable);
    }

    #[Test]
    public function getLatestVersionReturnsNullWhenNoRelease(): void
    {
        $this->releaseRepo->method('findLatestStable')->willReturn(null);

        self::assertNull($this->service->getLatestVersion(ReleasePlatform::Ios));
    }

    #[Test]
    public function signupForBetaWithValidEmailCreatesSignup(): void
    {
        /** @var BetaSignupRepositoryInterface&MockObject $betaRepo */
        $betaRepo = $this->createMock(BetaSignupRepositoryInterface::class);
        $betaRepo->method('findByEmail')->willReturn(null);
        $betaRepo->method('countByEmailToday')->willReturn(0);
        $betaRepo->expects(self::once())->method('save');

        $service = new ReleaseService($this->releaseRepo, $betaRepo);

        $signup = $service->signupForBeta(
            'user@example.com',
            DeviceType::Android,
            ['Canon', 'Sony'],
        );

        self::assertSame('user@example.com', $signup->email);
        self::assertSame(DeviceType::Android, $signup->deviceType);
        self::assertSame(['Canon', 'Sony'], $signup->cameraBrands);
    }

    #[Test]
    public function signupForBetaWithInvalidEmailThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid email/i');

        $this->service->signupForBeta('not-an-email', DeviceType::Ios, []);
    }

    #[Test]
    public function signupForBetaWithExistingEmailThrows(): void
    {
        $existing = BetaSignup::create('user@example.com', DeviceType::Both, []);
        $this->betaSignupRepo->method('findByEmail')->willReturn($existing);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already registered/i');

        $this->service->signupForBeta('user@example.com', DeviceType::Both, []);
    }

    #[Test]
    public function signupForBetaRateLimitExceededThrows(): void
    {
        $this->betaSignupRepo->method('findByEmail')->willReturn(null);
        $this->betaSignupRepo->method('countByEmailToday')->willReturn(3);

        $this->expectException(OverflowException::class);
        $this->expectExceptionMessageMatches('/Rate limit/i');

        $this->service->signupForBeta('user@example.com', DeviceType::Android, []);
    }

    #[Test]
    public function createReleaseWithValidDataSavesRelease(): void
    {
        /** @var ReleaseRepositoryInterface&MockObject $releaseRepo */
        $releaseRepo = $this->createMock(ReleaseRepositoryInterface::class);
        $releaseRepo->expects(self::once())->method('save');

        $service = new ReleaseService($releaseRepo, $this->betaSignupRepo);

        $release = $service->createRelease(
            version: '1.0.0',
            platform: ReleasePlatform::Web,
            releaseDate: new DateTimeImmutable('2026-06-01'),
            releaseNotes: 'Initial stable release with all features.',
            minimumOsVersion: 'any',
            downloadUrl: 'https://example.com/download',
            isBeta: false,
            isStable: true,
        );

        self::assertSame('1.0.0', $release->version);
        self::assertSame(ReleasePlatform::Web, $release->platform);
        self::assertTrue($release->isStable);
        self::assertFalse($release->isBeta);
    }

    #[Test]
    public function createReleaseWithEmptyVersionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Version/i');

        $this->service->createRelease(
            version: '',
            platform: ReleasePlatform::Android,
            releaseDate: new DateTimeImmutable(),
            releaseNotes: 'Some notes',
            minimumOsVersion: '13',
        );
    }

    #[Test]
    public function createReleaseWithVersionTooLongThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Version/i');

        $this->service->createRelease(
            version: str_repeat('v', 33),
            platform: ReleasePlatform::Android,
            releaseDate: new DateTimeImmutable(),
            releaseNotes: 'Some notes',
            minimumOsVersion: '13',
        );
    }

    #[Test]
    public function createReleaseWithEmptyNotesThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Release notes/i');

        $this->service->createRelease(
            version: '1.0.0',
            platform: ReleasePlatform::Ios,
            releaseDate: new DateTimeImmutable(),
            releaseNotes: '',
            minimumOsVersion: '16',
        );
    }

    #[Test]
    public function listReleasesReturnsPaginatedResults(): void
    {
        $releases = [$this->buildRelease('1.0.0'), $this->buildRelease('1.1.0')];
        $paginationResult = new PaginationResult(items: $releases, total: 2, hasMore: false, perPage: 20);

        $this->releaseRepo->method('findAll')->willReturn($paginationResult);

        $result = $this->service->listReleases(1, 20);

        self::assertCount(2, $result->items);
        self::assertSame(2, $result->total);
    }

    #[Test]
    public function markAsStableReturnsUpdatedRelease(): void
    {
        $release = $this->buildRelease('1.0.0', isStable: false);

        /** @var ReleaseRepositoryInterface&MockObject $releaseRepo */
        $releaseRepo = $this->createMock(ReleaseRepositoryInterface::class);
        $releaseRepo->method('findById')->willReturn($release);
        $releaseRepo->expects(self::once())->method('save');

        $service = new ReleaseService($releaseRepo, $this->betaSignupRepo);

        $updated = $service->markAsStable('rel-001');

        self::assertNotNull($updated);
        self::assertTrue($updated->isStable);
    }

    #[Test]
    public function markAsStableReturnsNullForNonexistentRelease(): void
    {
        $this->releaseRepo->method('findById')->willReturn(null);

        self::assertNull($this->service->markAsStable('nonexistent'));
    }

    private function buildRelease(string $version, bool $isStable = false): Release
    {
        return new Release(
            id: 'rel-001',
            version: $version,
            platform: ReleasePlatform::Android,
            releaseDate: new DateTimeImmutable('2026-01-01'),
            releaseNotes: 'Release notes for ' . $version,
            minimumOsVersion: '13',
            downloadUrl: null,
            isBeta: false,
            isStable: $isStable,
            createdAt: new DateTimeImmutable(),
        );
    }
}
