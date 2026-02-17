<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Releases\Release;
use Pulsar\Extension\Releases\ReleasePlatform;

use function strlen;

final class ReleaseTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $date = new DateTimeImmutable('2026-03-01');
        $created = new DateTimeImmutable('2026-02-28');

        $release = new Release(
            id: 'rel-abc',
            version: '2.1.0',
            platform: ReleasePlatform::Android,
            releaseDate: $date,
            releaseNotes: 'Bug fixes',
            minimumOsVersion: '10.0',
            downloadUrl: 'https://example.com/app.apk',
            isBeta: false,
            isStable: true,
            createdAt: $created,
        );

        self::assertSame('rel-abc', $release->id);
        self::assertSame('2.1.0', $release->version);
        self::assertSame(ReleasePlatform::Android, $release->platform);
        self::assertSame($date, $release->releaseDate);
        self::assertSame('Bug fixes', $release->releaseNotes);
        self::assertSame('10.0', $release->minimumOsVersion);
        self::assertSame('https://example.com/app.apk', $release->downloadUrl);
        self::assertFalse($release->isBeta);
        self::assertTrue($release->isStable);
        self::assertSame($created, $release->createdAt);
    }

    #[Test]
    public function createGeneratesIdAndSetsDefaults(): void
    {
        $date = new DateTimeImmutable('2026-06-01');

        $release = Release::create(
            version: '3.0.0',
            platform: ReleasePlatform::Ios,
            releaseDate: $date,
            releaseNotes: 'New features and improvements',
            minimumOsVersion: '15.0',
        );

        self::assertSame(32, strlen($release->id));
        self::assertSame('3.0.0', $release->version);
        self::assertSame(ReleasePlatform::Ios, $release->platform);
        self::assertSame('New features and improvements', $release->releaseNotes);
        self::assertNull($release->downloadUrl);
        self::assertFalse($release->isBeta);
        self::assertFalse($release->isStable);
        self::assertInstanceOf(DateTimeImmutable::class, $release->createdAt);
    }

    #[Test]
    public function createWithBetaFlag(): void
    {
        $release = Release::create(
            version: '3.0.0-beta.1',
            platform: ReleasePlatform::Web,
            releaseDate: new DateTimeImmutable(),
            releaseNotes: 'Beta release',
            minimumOsVersion: '1.0',
            isBeta: true,
        );

        self::assertTrue($release->isBeta);
        self::assertFalse($release->isStable);
    }

    #[Test]
    public function createWithDownloadUrl(): void
    {
        $release = Release::create(
            version: '2.0.0',
            platform: ReleasePlatform::Android,
            releaseDate: new DateTimeImmutable(),
            releaseNotes: 'Stable release',
            minimumOsVersion: '8.0',
            downloadUrl: 'https://cdn.example.com/app-2.0.0.apk',
            isStable: true,
        );

        self::assertSame('https://cdn.example.com/app-2.0.0.apk', $release->downloadUrl);
        self::assertTrue($release->isStable);
    }

    #[Test]
    public function createGeneratesUniqueIds(): void
    {
        $r1 = Release::create('1.0', ReleasePlatform::Web, new DateTimeImmutable(), 'Notes', '1.0');
        $r2 = Release::create('1.1', ReleasePlatform::Web, new DateTimeImmutable(), 'Notes', '1.0');

        self::assertNotSame($r1->id, $r2->id);
    }

    #[Test]
    public function markStableReturnsNewInstanceWithStableTrue(): void
    {
        $release = Release::create(
            version: '2.0.0-beta',
            platform: ReleasePlatform::Ios,
            releaseDate: new DateTimeImmutable(),
            releaseNotes: 'Beta notes',
            minimumOsVersion: '14.0',
            isBeta: true,
        );

        self::assertFalse($release->isStable);

        $stable = $release->markStable();

        self::assertTrue($stable->isStable);
        self::assertFalse($release->isStable);
        self::assertSame($release->id, $stable->id);
        self::assertSame($release->version, $stable->version);
    }
}
