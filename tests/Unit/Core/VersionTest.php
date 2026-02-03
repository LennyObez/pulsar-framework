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

        // @phpstan-ignore notIdentical.alwaysFalse (condition is valid when PRERELEASE is set in future versions)
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
    public function versionConstantsAreCorrectTypes(): void
    {
        // Verify major is an integer >= 0
        self::assertGreaterThanOrEqual(0, Version::MAJOR);

        // Verify minor is an integer >= 0
        self::assertGreaterThanOrEqual(0, Version::MINOR);

        // Verify patch is an integer >= 0
        self::assertGreaterThanOrEqual(0, Version::PATCH);

        // Verify prerelease is a string (even if empty)
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertIsString(Version::PRERELEASE);
    }

    #[Test]
    public function currentVersionIs060(): void
    {
        self::assertSame(0, Version::MAJOR);
        self::assertSame(6, Version::MINOR);
        self::assertSame(0, Version::PATCH);
        self::assertSame('0.6.0', Version::short());
    }
}
