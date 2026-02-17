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

        self::assertStringContainsString('composer.lock', $check->getDescription());
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
