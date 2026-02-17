<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases\Tests\Unit\Internal;

use DateTimeImmutable;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
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
    private BetaSignupRepositoryInterface&Stub $betaRepo;
    private ReleaseService $service;

    protected function setUp(): void
    {
        $this->releaseRepo = $this->createStub(ReleaseRepositoryInterface::class);
        $this->betaRepo = $this->createStub(BetaSignupRepositoryInterface::class);
        $this->service = new ReleaseService($this->releaseRepo, $this->betaRepo);
    }

    #[Test]
    public function getLatestVersionDelegatesToRepository(): void
    {
        $release = Release::create('1.0.0', ReleasePlatform::Android, new DateTimeImmutable(), 'Notes', '8.0', isStable: true);
        $this->releaseRepo->method('findLatestStable')->willReturn($release);

        $result = $this->service->getLatestVersion(ReleasePlatform::Android);

        self::assertNotNull($result);
        self::assertSame('1.0.0', $result->version);
    }

    #[Test]
    public function getLatestVersionReturnsNullWhenNone(): void
    {
        $this->releaseRepo->method('findLatestStable')->willReturn(null);

        $result = $this->service->getLatestVersion(ReleasePlatform::Web);

        self::assertNull($result);
    }

    #[Test]
    public function signupForBetaCreatesSignup(): void
    {
        $this->betaRepo->method('findByEmail')->willReturn(null);
        $this->betaRepo->method('countByEmailToday')->willReturn(0);

        $result = $this->service->signupForBeta('valid@example.com', DeviceType::Android, ['Canon']);

        self::assertInstanceOf(BetaSignup::class, $result);
        self::assertSame('valid@example.com', $result->email);
        self::assertSame(DeviceType::Android, $result->deviceType);
    }

    #[Test]
    public function signupForBetaRejectsInvalidEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email');

        $this->service->signupForBeta('not-an-email', DeviceType::Ios, []);
    }

    #[Test]
    public function signupForBetaRejectsDuplicateEmail(): void
    {
        $existing = BetaSignup::create('user@test.com', DeviceType::Both);
        $this->betaRepo->method('findByEmail')->willReturn($existing);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already registered');

        $this->service->signupForBeta('user@test.com', DeviceType::Both, []);
    }

    #[Test]
    public function signupForBetaRejectsWhenRateLimited(): void
    {
        $this->betaRepo->method('findByEmail')->willReturn(null);
        $this->betaRepo->method('countByEmailToday')->willReturn(3);

        $this->expectException(OverflowException::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        $this->service->signupForBeta('new@example.com', DeviceType::Android, []);
    }

    #[Test]
    public function createReleaseSucceeds(): void
    {
        $date = new DateTimeImmutable('2026-06-01');

        $result = $this->service->createRelease(
            version: '2.0.0',
            platform: ReleasePlatform::Ios,
            releaseDate: $date,
            releaseNotes: 'Major update with new features',
            minimumOsVersion: '15.0',
            downloadUrl: 'https://cdn.example.com/app.ipa',
            isStable: true,
        );

        self::assertInstanceOf(Release::class, $result);
        self::assertSame('2.0.0', $result->version);
        self::assertSame(ReleasePlatform::Ios, $result->platform);
    }

    #[Test]
    public function createReleaseRejectsEmptyVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Version must be between');

        $this->service->createRelease('', ReleasePlatform::Web, new DateTimeImmutable(), 'Notes', '1.0');
    }

    #[Test]
    public function createReleaseRejectsTooLongVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Version must be between');

        $this->service->createRelease(str_repeat('v', 33), ReleasePlatform::Web, new DateTimeImmutable(), 'Notes', '1.0');
    }

    #[Test]
    public function createReleaseRejectsEmptyNotes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Release notes must be between');

        $this->service->createRelease('1.0', ReleasePlatform::Web, new DateTimeImmutable(), '', '1.0');
    }

    #[Test]
    public function createReleaseRejectsTooLongNotes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Release notes must be between');

        $this->service->createRelease('1.0', ReleasePlatform::Web, new DateTimeImmutable(), str_repeat('n', 50001), '1.0');
    }

    #[Test]
    public function markAsStableReturnsUpdatedRelease(): void
    {
        $release = Release::create('1.0.0-beta', ReleasePlatform::Android, new DateTimeImmutable(), 'Beta', '8.0', isBeta: true);
        $this->releaseRepo->method('findById')->willReturn($release);

        $result = $this->service->markAsStable($release->id);

        self::assertNotNull($result);
        self::assertTrue($result->isStable);
    }

    #[Test]
    public function markAsStableReturnsNullWhenNotFound(): void
    {
        $this->releaseRepo->method('findById')->willReturn(null);

        $result = $this->service->markAsStable('nonexistent');

        self::assertNull($result);
    }
}
