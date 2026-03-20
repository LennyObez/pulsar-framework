<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Core\Version;

use function dirname;

#[CoversClass(Version::class)]
final class VersionTest extends TestCase
{
    #[Test]
    public function fullVersionIncludesPrerelease(): void
    {
        self::assertSame('1.0.0-rc.11', Version::full());
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
        self::assertSame('-rc.11', Version::PRERELEASE_SUFFIX);
        self::assertSame('1.0.0', Version::short());
        self::assertSame('1.0.0-rc.11', Version::full());
    }

    /**
     * F387.1 / F387.M3 / F23.1: the `Version::PRERELEASE_SUFFIX`
     * constant has drifted from `composer.json::version` 12 times
     * across the rc.x cycle — every release reproduced the
     * mismatch because nothing tied the two together. This test is
     * the pre-merge guard: any PR that bumps one without the other
     * fails CI before it can land. Reading composer.json from disk
     * is fine here — it is checked in, frozen at test time, and
     * the hot path is unaffected.
     */
    #[Test]
    public function fullVersionMatchesComposerJson(): void
    {
        $composerPath = dirname(__DIR__, 3) . '/composer.json';
        self::assertFileExists($composerPath);

        $contents = file_get_contents($composerPath);
        self::assertNotFalse($contents);

        /** @var array{version?: string} $manifest */
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertArrayHasKey('version', $manifest);

        self::assertSame(
            $manifest['version'],
            Version::full(),
            'Version::PRERELEASE_SUFFIX drifted from composer.json::version. '
            . 'Update both together.',
        );
    }
}
