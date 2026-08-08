<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Check;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Deploy\Check\DependencyIntegrityCheck;
use Pulsar\Deploy\CheckSeverity;

use function dirname;

#[CoversClass(DependencyIntegrityCheck::class)]
final class DependencyIntegrityCheckTest extends TestCase
{
    #[Test]
    public function getName(): void
    {
        $check = new DependencyIntegrityCheck(__DIR__);

        self::assertSame('dependency-integrity', $check->getName());
    }

    #[Test]
    public function getDescription(): void
    {
        $check = new DependencyIntegrityCheck(__DIR__);

        // The description names the records it compares. It used to promise
        // "composer.lock checksums" while only version strings were read.
        self::assertStringContainsString('composer.lock', $check->getDescription());
        self::assertStringContainsString('dist references', $check->getDescription());
        self::assertStringContainsString('installed.json', $check->getDescription());
    }

    #[Test]
    public function errorWhenComposerLockMissing(): void
    {
        // __DIR__ has no composer.lock
        $check = new DependencyIntegrityCheck(__DIR__);
        $result = $check->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('composer.lock not found', $result->message);
    }

    #[Test]
    public function passWhenRunAgainstRealProjectRoot(): void
    {
        // The real project root has a valid composer.lock and vendor/
        $projectRoot = dirname(__DIR__, 4);
        $check = new DependencyIntegrityCheck($projectRoot);
        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('match', $result->message);
    }

    #[Test]
    public function comparePackagesReturnsEmptyWhenAllMatch(): void
    {
        $check = new DependencyIntegrityCheck(__DIR__);

        $lockData = [
            'packages' => [
                ['name' => 'vendor/alpha', 'version' => 'v1.0.0'],
                ['name' => 'vendor/beta', 'version' => 'v2.3.1'],
            ],
        ];

        $installedData = [
            'packages' => [
                ['name' => 'vendor/alpha', 'version' => 'v1.0.0'],
                ['name' => 'vendor/beta', 'version' => 'v2.3.1'],
            ],
        ];

        $mismatches = $check->comparePackages($lockData, $installedData);

        self::assertSame([], $mismatches);
    }

    #[Test]
    public function comparePackagesDetectsVersionMismatch(): void
    {
        $check = new DependencyIntegrityCheck(__DIR__);

        $lockData = [
            'packages' => [
                ['name' => 'vendor/alpha', 'version' => 'v1.0.0'],
            ],
        ];

        $installedData = [
            'packages' => [
                ['name' => 'vendor/alpha', 'version' => 'v1.0.1'],
            ],
        ];

        $mismatches = $check->comparePackages($lockData, $installedData);

        self::assertCount(1, $mismatches);
        self::assertStringContainsString('vendor/alpha', $mismatches[0]);
        self::assertStringContainsString('v1.0.0', $mismatches[0]);
        self::assertStringContainsString('v1.0.1', $mismatches[0]);
    }

    #[Test]
    public function comparePackagesDetectsDistReferenceMismatchAtTheSameVersion(): void
    {
        $check = new DependencyIntegrityCheck(__DIR__);

        // An upstream re-tag keeps the version string and moves the commit the
        // archive was cut from, so a version-only comparison sees nothing.
        $lockData = [
            'packages' => [
                ['name' => 'vendor/alpha', 'version' => 'v1.0.0', 'dist' => ['reference' => 'aaaa1111', 'shasum' => '']],
            ],
        ];

        $installedData = [
            'packages' => [
                ['name' => 'vendor/alpha', 'version' => 'v1.0.0', 'dist' => ['reference' => 'bbbb2222', 'shasum' => '']],
            ],
        ];

        $mismatches = $check->comparePackages($lockData, $installedData);

        self::assertCount(1, $mismatches);
        self::assertStringContainsString('vendor/alpha', $mismatches[0]);
        self::assertStringContainsString('dist reference differs', $mismatches[0]);
        self::assertStringContainsString('aaaa1111', $mismatches[0]);
        self::assertStringContainsString('bbbb2222', $mismatches[0]);
    }

    #[Test]
    public function comparePackagesDetectsDistChecksumMismatchAtTheSameReference(): void
    {
        $check = new DependencyIntegrityCheck(__DIR__);

        $lockData = [
            'packages' => [
                ['name' => 'vendor/alpha', 'version' => 'v1.0.0', 'dist' => ['reference' => 'aaaa1111', 'shasum' => 'cccc3333']],
            ],
        ];

        $installedData = [
            'packages' => [
                ['name' => 'vendor/alpha', 'version' => 'v1.0.0', 'dist' => ['reference' => 'aaaa1111', 'shasum' => 'dddd4444']],
            ],
        ];

        $mismatches = $check->comparePackages($lockData, $installedData);

        self::assertCount(1, $mismatches);
        self::assertStringContainsString('dist checksum differs', $mismatches[0]);
    }

    #[Test]
    public function comparePackagesAcceptsMatchingDistMetadata(): void
    {
        $check = new DependencyIntegrityCheck(__DIR__);

        $packages = [
            'packages' => [
                ['name' => 'vendor/alpha', 'version' => 'v1.0.0', 'dist' => ['reference' => 'aaaa1111', 'shasum' => 'cccc3333']],
            ],
        ];

        self::assertSame([], $check->comparePackages($packages, $packages));
    }

    #[Test]
    public function comparePackagesComparesByVersionOnlyWhenDistMetadataIsAbsent(): void
    {
        $check = new DependencyIntegrityCheck(__DIR__);

        // Composer leaves shasum empty for VCS dists and records no dist block
        // at all for path repositories; neither is a mismatch.
        $lockData = [
            'packages' => [
                ['name' => 'vendor/alpha', 'version' => 'v1.0.0', 'dist' => ['reference' => 'aaaa1111', 'shasum' => '']],
                ['name' => 'vendor/beta', 'version' => 'v2.0.0'],
            ],
        ];

        $installedData = [
            'packages' => [
                ['name' => 'vendor/alpha', 'version' => 'v1.0.0', 'dist' => ['reference' => 'aaaa1111', 'shasum' => 'cccc3333']],
                ['name' => 'vendor/beta', 'version' => 'v2.0.0', 'dist' => ['reference' => 'bbbb2222', 'shasum' => '']],
            ],
        ];

        self::assertSame([], $check->comparePackages($lockData, $installedData));
    }

    #[Test]
    public function comparePackagesDetectsARetagInTheRealLockfileShape(): void
    {
        $check = new DependencyIntegrityCheck(__DIR__);

        // The shape Composer actually writes for a GitHub-hosted package: a
        // populated dist.reference and an empty shasum. Comparing shasum alone
        // would be a check that never fires.
        $dist = static fn(string $reference): array => [
            'type' => 'zip',
            'url' => 'https://api.github.com/repos/vendor/alpha/zipball/' . $reference,
            'reference' => $reference,
            'shasum' => '',
        ];

        $mismatches = $check->comparePackages(
            ['packages' => [['name' => 'vendor/alpha', 'version' => 'v1.0.0', 'dist' => $dist('a23a2bf4')]]],
            ['packages' => [['name' => 'vendor/alpha', 'version' => 'v1.0.0', 'dist' => $dist('deadbeef')]]],
        );

        self::assertCount(1, $mismatches);
        self::assertStringContainsString('dist reference differs', $mismatches[0]);
    }

    #[Test]
    public function comparePackagesDetectsMissingPackage(): void
    {
        $check = new DependencyIntegrityCheck(__DIR__);

        $lockData = [
            'packages' => [
                ['name' => 'vendor/alpha', 'version' => 'v1.0.0'],
                ['name' => 'vendor/beta', 'version' => 'v2.0.0'],
            ],
        ];

        $installedData = [
            'packages' => [
                ['name' => 'vendor/alpha', 'version' => 'v1.0.0'],
            ],
        ];

        $mismatches = $check->comparePackages($lockData, $installedData);

        self::assertCount(1, $mismatches);
        self::assertStringContainsString('vendor/beta', $mismatches[0]);
        self::assertStringContainsString('missing', $mismatches[0]);
    }

    #[Test]
    public function comparePackagesHandlesEmptyLists(): void
    {
        $check = new DependencyIntegrityCheck(__DIR__);

        $mismatches = $check->comparePackages(
            ['packages' => []],
            ['packages' => []],
        );

        self::assertSame([], $mismatches);
    }

    #[Test]
    public function comparePackagesSkipsNonArrayPackages(): void
    {
        $check = new DependencyIntegrityCheck(__DIR__);

        $lockData = [
            'packages' => [
                'not-an-array',
                ['name' => 'vendor/alpha', 'version' => 'v1.0.0'],
            ],
        ];

        $installedData = [
            'packages' => [
                ['name' => 'vendor/alpha', 'version' => 'v1.0.0'],
            ],
        ];

        $mismatches = $check->comparePackages($lockData, $installedData);

        self::assertSame([], $mismatches);
    }

    #[Test]
    public function comparePackagesSkipsPackagesWithMissingFields(): void
    {
        $check = new DependencyIntegrityCheck(__DIR__);

        $lockData = [
            'packages' => [
                ['name' => 'vendor/alpha'],  // missing version
                ['version' => 'v1.0.0'],      // missing name
            ],
        ];

        $installedData = [
            'packages' => [],
        ];

        $mismatches = $check->comparePackages($lockData, $installedData);

        self::assertSame([], $mismatches);
    }

    #[Test]
    public function comparePackagesHandlesMissingPackagesKey(): void
    {
        $check = new DependencyIntegrityCheck(__DIR__);

        $mismatches = $check->comparePackages([], []);

        self::assertSame([], $mismatches);
    }

    #[Test]
    public function comparePackagesMultipleMismatches(): void
    {
        $check = new DependencyIntegrityCheck(__DIR__);

        $lockData = [
            'packages' => [
                ['name' => 'vendor/alpha', 'version' => 'v1.0.0'],
                ['name' => 'vendor/beta', 'version' => 'v2.0.0'],
                ['name' => 'vendor/gamma', 'version' => 'v3.0.0'],
            ],
        ];

        $installedData = [
            'packages' => [
                ['name' => 'vendor/alpha', 'version' => 'v1.0.0'],
                ['name' => 'vendor/beta', 'version' => 'v2.9.9'],
                // gamma missing
            ],
        ];

        $mismatches = $check->comparePackages($lockData, $installedData);

        self::assertCount(2, $mismatches);
    }
}
