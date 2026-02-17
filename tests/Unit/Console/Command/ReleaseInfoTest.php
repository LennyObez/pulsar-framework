<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\ReleaseInfo;

#[CoversClass(ReleaseInfo::class)]
final class ReleaseInfoTest extends TestCase
{
    #[Test]
    public function constructorAssignsAllProperties(): void
    {
        $info = new ReleaseInfo(
            version: '1.2.3',
            changelog: 'Fixed bugs',
            releaseDate: '2026-03-20',
            downloadUrl: 'https://example.com/download',
        );

        self::assertSame('1.2.3', $info->version);
        self::assertSame('Fixed bugs', $info->changelog);
        self::assertSame('2026-03-20', $info->releaseDate);
        self::assertSame('https://example.com/download', $info->downloadUrl);
    }

    #[Test]
    public function downloadUrlDefaultsToEmptyString(): void
    {
        $info = new ReleaseInfo(
            version: '1.0.0',
            changelog: '',
            releaseDate: '2026-01-01',
        );

        self::assertSame('', $info->downloadUrl);
    }

    #[Test]
    public function handlesPreReleaseVersions(): void
    {
        $info = new ReleaseInfo(
            version: '1.0.0-rc.11',
            changelog: 'Release candidate',
            releaseDate: '2026-03-20',
        );

        self::assertSame('1.0.0-rc.11', $info->version);
    }
}
