<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Releases;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Releases\Release;
use Pulsar\Extension\Releases\ReleasePlatform;

use function strlen;

final class ReleaseEntityTest extends TestCase
{
    #[Test]
    public function createGeneratesIdAndSetsTimestamp(): void
    {
        $releaseDate = new DateTimeImmutable('2026-06-01');

        $release = Release::create(
            version: '2.0.0',
            platform: ReleasePlatform::Android,
            releaseDate: $releaseDate,
            releaseNotes: 'Major update with performance improvements.',
            minimumOsVersion: '13',
        );

        self::assertSame(32, strlen($release->id));
        self::assertSame('2.0.0', $release->version);
        self::assertSame(ReleasePlatform::Android, $release->platform);
        self::assertSame($releaseDate, $release->releaseDate);
        self::assertSame('Major update with performance improvements.', $release->releaseNotes);
        self::assertSame('13', $release->minimumOsVersion);
        self::assertNull($release->downloadUrl);
        self::assertFalse($release->isBeta);
        self::assertFalse($release->isStable);
        self::assertInstanceOf(DateTimeImmutable::class, $release->createdAt);
    }

    #[Test]
    public function createAcceptsOptionalParameters(): void
    {
        $release = Release::create(
            version: '2.0.0-beta.1',
            platform: ReleasePlatform::Ios,
            releaseDate: new DateTimeImmutable(),
            releaseNotes: 'Beta release.',
            minimumOsVersion: '16',
            downloadUrl: 'https://example.com/beta',
            isBeta: true,
            isStable: false,
        );

        self::assertSame('https://example.com/beta', $release->downloadUrl);
        self::assertTrue($release->isBeta);
        self::assertFalse($release->isStable);
    }

    #[Test]
    public function markStableReturnsCopyWithStableTrue(): void
    {
        $release = Release::create(
            version: '1.0.0',
            platform: ReleasePlatform::Web,
            releaseDate: new DateTimeImmutable(),
            releaseNotes: 'Initial release.',
            minimumOsVersion: 'any',
            isStable: false,
        );

        self::assertFalse($release->isStable);

        $stable = $release->markStable();

        self::assertTrue($stable->isStable);
        self::assertSame($release->id, $stable->id);
        self::assertSame($release->version, $stable->version);
        self::assertSame($release->platform, $stable->platform);
        self::assertSame($release->releaseNotes, $stable->releaseNotes);
    }

    #[Test]
    public function markStableOnAlreadyStableRelease(): void
    {
        $release = Release::create(
            version: '1.0.0',
            platform: ReleasePlatform::Android,
            releaseDate: new DateTimeImmutable(),
            releaseNotes: 'Stable release.',
            minimumOsVersion: '13',
            isStable: true,
        );

        $stable = $release->markStable();

        self::assertTrue($stable->isStable);
    }

    #[Test]
    public function constructorAcceptsAllParameters(): void
    {
        $releaseDate = new DateTimeImmutable('2026-06-01');
        $createdAt = new DateTimeImmutable('2026-05-15');

        $release = new Release(
            id: 'rel-custom',
            version: '1.2.3',
            platform: ReleasePlatform::Ios,
            releaseDate: $releaseDate,
            releaseNotes: 'Custom notes.',
            minimumOsVersion: '16.0',
            downloadUrl: 'https://example.com/dl',
            isBeta: true,
            isStable: false,
            createdAt: $createdAt,
        );

        self::assertSame('rel-custom', $release->id);
        self::assertSame('1.2.3', $release->version);
        self::assertSame(ReleasePlatform::Ios, $release->platform);
        self::assertSame($releaseDate, $release->releaseDate);
        self::assertSame('https://example.com/dl', $release->downloadUrl);
        self::assertTrue($release->isBeta);
        self::assertFalse($release->isStable);
        self::assertSame($createdAt, $release->createdAt);
    }
}
