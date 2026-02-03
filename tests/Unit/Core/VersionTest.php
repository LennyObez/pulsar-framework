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
        self::assertSame('1.0.0-rc.4', Version::full());
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
        self::assertGreaterThanOrEqual(0, Version::MAJOR);
        self::assertGreaterThanOrEqual(0, Version::MINOR);
        self::assertGreaterThanOrEqual(0, Version::PATCH);
    }

    #[Test]
    public function currentVersionIs100Rc2(): void
    {
        self::assertSame(1, Version::MAJOR);
        self::assertSame(0, Version::MINOR);
        self::assertSame(0, Version::PATCH);
        self::assertSame('-rc.4', Version::PRERELEASE_SUFFIX);
        self::assertSame('1.0.0', Version::short());
        self::assertSame('1.0.0-rc.4', Version::full());
    }
}
