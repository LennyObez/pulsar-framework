<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\License;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\License\AllowedLicensesConfig;
use Pulsar\SupplyChain\License\LicenseChecker;

#[CoversClass(LicenseChecker::class)]
final class LicenseCheckerTest extends TestCase
{
    #[Test]
    public function checkReturnsEmptyResultForNullLock(): void
    {
        $checker = new LicenseChecker();
        $result = $checker->check(null);

        self::assertSame([], $result->compliantPackages);
        self::assertSame([], $result->nonCompliantPackages);
        self::assertSame([], $result->unknownLicensePackages);
        self::assertTrue($result->isCompliant());
    }

    #[Test]
    public function checkIdentifiesCompliantMitPackage(): void
    {
        $lock = [
            'packages' => [
                ['name' => 'vendor/mit-lib', 'version' => 'v1.0.0', 'license' => ['MIT']],
            ],
        ];

        $checker = new LicenseChecker();
        $result = $checker->check($lock);

        self::assertCount(1, $result->compliantPackages);
        self::assertSame('vendor/mit-lib', $result->compliantPackages[0]['name']);
        self::assertSame('MIT', $result->compliantPackages[0]['license']);
        self::assertTrue($result->isCompliant());
    }

    #[Test]
    #[DataProvider('compliantLicenseProvider')]
    public function checkAcceptsAllDefaultAllowedLicenses(string $license): void
    {
        $lock = [
            'packages' => [
                ['name' => 'vendor/lib', 'version' => 'v1.0.0', 'license' => [$license]],
            ],
        ];

        $checker = new LicenseChecker();
        $result = $checker->check($lock);

        self::assertCount(1, $result->compliantPackages);
        self::assertSame([], $result->nonCompliantPackages);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function compliantLicenseProvider(): iterable
    {
        yield 'MIT' => ['MIT'];
        yield 'Apache-2.0' => ['Apache-2.0'];
        yield 'BSD-2-Clause' => ['BSD-2-Clause'];
        yield 'BSD-3-Clause' => ['BSD-3-Clause'];
        yield 'ISC' => ['ISC'];
        yield 'LGPL-2.1-only' => ['LGPL-2.1-only'];
        yield 'LGPL-3.0-only' => ['LGPL-3.0-only'];
        yield 'GPL-2.0-only' => ['GPL-2.0-only'];
        yield 'GPL-3.0-only' => ['GPL-3.0-only'];
    }

    #[Test]
    public function checkIdentifiesNonCompliantLicense(): void
    {
        $lock = [
            'packages' => [
                ['name' => 'vendor/proprietary', 'version' => 'v2.0.0', 'license' => ['SSPL-1.0']],
            ],
        ];

        $checker = new LicenseChecker();
        $result = $checker->check($lock);

        self::assertSame([], $result->compliantPackages);
        self::assertCount(1, $result->nonCompliantPackages);
        self::assertSame('vendor/proprietary', $result->nonCompliantPackages[0]['name']);
        self::assertSame('SSPL-1.0', $result->nonCompliantPackages[0]['license']);
        self::assertFalse($result->isCompliant());
    }

    #[Test]
    public function checkIdentifiesUnknownLicenseWhenEmpty(): void
    {
        $lock = [
            'packages' => [
                ['name' => 'vendor/no-license', 'version' => 'v1.0.0', 'license' => []],
            ],
        ];

        $checker = new LicenseChecker();
        $result = $checker->check($lock);

        self::assertSame([], $result->compliantPackages);
        self::assertSame([], $result->nonCompliantPackages);
        self::assertCount(1, $result->unknownLicensePackages);
        self::assertSame('vendor/no-license', $result->unknownLicensePackages[0]['name']);
        self::assertFalse($result->isCompliant());
    }

    #[Test]
    public function checkIdentifiesUnknownLicenseWhenMissing(): void
    {
        $lock = [
            'packages' => [
                ['name' => 'vendor/missing', 'version' => 'v1.0.0'],
            ],
        ];

        $checker = new LicenseChecker();
        $result = $checker->check($lock);

        self::assertCount(1, $result->unknownLicensePackages);
    }

    #[Test]
    public function checkHandlesMixedCompliance(): void
    {
        $lock = [
            'packages' => [
                ['name' => 'good/a', 'version' => 'v1.0', 'license' => ['MIT']],
                ['name' => 'bad/b', 'version' => 'v2.0', 'license' => ['BUSL-1.1']],
                ['name' => 'unknown/c', 'version' => 'v3.0'],
                ['name' => 'good/d', 'version' => 'v4.0', 'license' => ['Apache-2.0']],
            ],
        ];

        $checker = new LicenseChecker();
        $result = $checker->check($lock);

        self::assertCount(2, $result->compliantPackages);
        self::assertCount(1, $result->nonCompliantPackages);
        self::assertCount(1, $result->unknownLicensePackages);
        self::assertFalse($result->isCompliant());
    }

    #[Test]
    public function checkProcessesDevPackages(): void
    {
        $lock = [
            'packages' => [],
            'packages-dev' => [
                ['name' => 'dev/tool', 'version' => 'v1.0.0', 'license' => ['MIT']],
                ['name' => 'dev/proprietary', 'version' => 'v2.0.0', 'license' => ['Commercial']],
            ],
        ];

        $checker = new LicenseChecker();
        $result = $checker->check($lock);

        self::assertCount(1, $result->compliantPackages);
        self::assertCount(1, $result->nonCompliantPackages);
        self::assertSame('dev/proprietary', $result->nonCompliantPackages[0]['name']);
    }

    #[Test]
    public function checkHandlesEmptyLockFile(): void
    {
        $lock = ['packages' => [], 'packages-dev' => []];

        $checker = new LicenseChecker();
        $result = $checker->check($lock);

        self::assertTrue($result->isCompliant());
        self::assertSame([], $result->compliantPackages);
    }

    #[Test]
    public function checkSkipsNonArrayPackageEntries(): void
    {
        $lock = [
            'packages' => [
                'not-an-array',
                ['name' => 'good/pkg', 'version' => 'v1.0', 'license' => ['MIT']],
            ],
        ];

        $checker = new LicenseChecker();
        $result = $checker->check($lock);

        self::assertCount(1, $result->compliantPackages);
    }

    #[Test]
    public function checkSkipsEntriesWithNonStringName(): void
    {
        $lock = [
            'packages' => [
                ['name' => 123, 'version' => 'v1.0', 'license' => ['MIT']],
                ['name' => 'real/pkg', 'version' => 'v1.0', 'license' => ['MIT']],
            ],
        ];

        $checker = new LicenseChecker();
        $result = $checker->check($lock);

        self::assertCount(1, $result->compliantPackages);
        self::assertSame('real/pkg', $result->compliantPackages[0]['name']);
    }

    #[Test]
    public function checkSkipsEntriesWithNonStringVersion(): void
    {
        $lock = [
            'packages' => [
                ['name' => 'pkg/a', 'version' => null, 'license' => ['MIT']],
            ],
        ];

        $checker = new LicenseChecker();
        $result = $checker->check($lock);

        self::assertSame([], $result->compliantPackages);
    }

    #[Test]
    public function customAllowlistIsRespected(): void
    {
        $config = new AllowedLicensesConfig(['MIT', 'Custom-1.0']);

        $lock = [
            'packages' => [
                ['name' => 'a/b', 'version' => 'v1.0', 'license' => ['Custom-1.0']],
                ['name' => 'c/d', 'version' => 'v2.0', 'license' => ['Apache-2.0']],
            ],
        ];

        $checker = new LicenseChecker($config);
        $result = $checker->check($lock);

        // Custom-1.0 is in custom allowlist -> compliant
        self::assertCount(1, $result->compliantPackages);
        self::assertSame('a/b', $result->compliantPackages[0]['name']);

        // Apache-2.0 is NOT in custom allowlist -> non-compliant
        self::assertCount(1, $result->nonCompliantPackages);
        self::assertSame('c/d', $result->nonCompliantPackages[0]['name']);
    }

    #[Test]
    public function checkFromJsonParsesAndChecks(): void
    {
        $json = json_encode([
            'packages' => [
                ['name' => 'vendor/pkg', 'version' => 'v1.0', 'license' => ['MIT']],
            ],
        ], JSON_THROW_ON_ERROR);

        $checker = new LicenseChecker();
        $result = $checker->checkFromJson($json);

        self::assertCount(1, $result->compliantPackages);
        self::assertTrue($result->isCompliant());
    }

    #[Test]
    public function checkFromJsonHandlesEmptyString(): void
    {
        $checker = new LicenseChecker();
        $result = $checker->checkFromJson('');

        self::assertTrue($result->isCompliant());
        self::assertSame([], $result->compliantPackages);
    }

    #[Test]
    public function checkHandlesMultipleLicensesPerPackage(): void
    {
        $lock = [
            'packages' => [
                ['name' => 'dual/license', 'version' => 'v1.0', 'license' => ['MIT', 'Apache-2.0']],
            ],
        ];

        $checker = new LicenseChecker();
        $result = $checker->check($lock);

        self::assertCount(1, $result->compliantPackages);
        self::assertSame('MIT, Apache-2.0', $result->compliantPackages[0]['license']);
    }

    #[Test]
    public function checkRejectsPackageIfAnyLicenseIsNonCompliant(): void
    {
        $lock = [
            'packages' => [
                ['name' => 'mixed/license', 'version' => 'v1.0', 'license' => ['MIT', 'SSPL-1.0']],
            ],
        ];

        $checker = new LicenseChecker();
        $result = $checker->check($lock);

        self::assertSame([], $result->compliantPackages);
        self::assertCount(1, $result->nonCompliantPackages);
    }

    #[Test]
    public function checkHandlesLicenseArrayWithNonStringElements(): void
    {
        $lock = [
            'packages' => [
                ['name' => 'weird/license', 'version' => 'v1.0', 'license' => [123, null]],
            ],
        ];

        $checker = new LicenseChecker();
        $result = $checker->check($lock);

        // No valid string licenses -> unknown
        self::assertCount(1, $result->unknownLicensePackages);
    }
}
