<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\License;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\License\LicenseCheckResult;

#[CoversClass(LicenseCheckResult::class)]
final class LicenseCheckResultTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $result = new LicenseCheckResult(
            compliantPackages: [['name' => 'a/b', 'version' => '1.0', 'license' => 'MIT']],
            nonCompliantPackages: [['name' => 'c/d', 'version' => '2.0', 'license' => 'SSPL']],
            unknownLicensePackages: [['name' => 'e/f', 'version' => '3.0']],
        );

        self::assertCount(1, $result->compliantPackages);
        self::assertCount(1, $result->nonCompliantPackages);
        self::assertCount(1, $result->unknownLicensePackages);
    }

    #[Test]
    public function isCompliantReturnsTrueWhenAllPackagesAreCompliant(): void
    {
        $result = new LicenseCheckResult(
            compliantPackages: [['name' => 'a/b', 'version' => '1.0', 'license' => 'MIT']],
            nonCompliantPackages: [],
            unknownLicensePackages: [],
        );

        self::assertTrue($result->isCompliant());
    }

    #[Test]
    public function isCompliantReturnsFalseWhenNonCompliantPackagesExist(): void
    {
        $result = new LicenseCheckResult(
            compliantPackages: [['name' => 'a/b', 'version' => '1.0', 'license' => 'MIT']],
            nonCompliantPackages: [['name' => 'c/d', 'version' => '2.0', 'license' => 'SSPL']],
            unknownLicensePackages: [],
        );

        self::assertFalse($result->isCompliant());
    }

    #[Test]
    public function isCompliantReturnsFalseWhenUnknownPackagesExist(): void
    {
        $result = new LicenseCheckResult(
            compliantPackages: [],
            nonCompliantPackages: [],
            unknownLicensePackages: [['name' => 'e/f', 'version' => '3.0']],
        );

        self::assertFalse($result->isCompliant());
    }

    #[Test]
    public function isCompliantReturnsTrueForEmptyResult(): void
    {
        $result = new LicenseCheckResult(
            compliantPackages: [],
            nonCompliantPackages: [],
            unknownLicensePackages: [],
        );

        self::assertTrue($result->isCompliant());
    }

    #[Test]
    public function isCompliantReturnsFalseWhenBothNonCompliantAndUnknownExist(): void
    {
        $result = new LicenseCheckResult(
            compliantPackages: [['name' => 'ok/pkg', 'version' => '1.0', 'license' => 'MIT']],
            nonCompliantPackages: [['name' => 'bad/pkg', 'version' => '1.0', 'license' => 'SSPL']],
            unknownLicensePackages: [['name' => 'mystery/pkg', 'version' => '1.0']],
        );

        self::assertFalse($result->isCompliant());
    }
}
