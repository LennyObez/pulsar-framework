<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Core\Version;

#[CoversClass(Version::class)]
final class VersionTest extends TestCase
{
    #[Test]
    public function fullVersionIncludesPrerelease(): void
    {
        $version = Version::full();

        self::assertStringContainsString((string) Version::MAJOR, $version);
        self::assertStringContainsString((string) Version::MINOR, $version);
        self::assertStringContainsString((string) Version::PATCH, $version);

        if (Version::PRERELEASE !== '') {
            self::assertStringContainsString(Version::PRERELEASE, $version);
        }
    }

    #[Test]
    public function shortVersionExcludesPrerelease(): void
    {
        $version = Version::short();

        self::assertSame(
            Version::MAJOR . '.' . Version::MINOR . '.' . Version::PATCH,
            $version,
        );
    }

    #[Test]
    public function versionConstantsAreIntegers(): void
    {
        self::assertIsInt(Version::MAJOR);
        self::assertIsInt(Version::MINOR);
        self::assertIsInt(Version::PATCH);
    }
}
