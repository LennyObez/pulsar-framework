<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\License;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\License\AllowedLicensesConfig;

#[CoversClass(AllowedLicensesConfig::class)]
final class AllowedLicensesConfigTest extends TestCase
{
    #[Test]
    public function defaultAllowlistContainsExpectedLicenses(): void
    {
        $config = new AllowedLicensesConfig();

        self::assertContains('MIT', $config->allowedLicenses);
        self::assertContains('Apache-2.0', $config->allowedLicenses);
        self::assertContains('BSD-2-Clause', $config->allowedLicenses);
        self::assertContains('BSD-3-Clause', $config->allowedLicenses);
        self::assertContains('ISC', $config->allowedLicenses);
        self::assertContains('LGPL-2.1-only', $config->allowedLicenses);
        self::assertContains('LGPL-3.0-only', $config->allowedLicenses);
        self::assertContains('GPL-2.0-only', $config->allowedLicenses);
        self::assertContains('GPL-3.0-only', $config->allowedLicenses);
    }

    #[Test]
    public function defaultAllowlistHasExactlyNineEntries(): void
    {
        $config = new AllowedLicensesConfig();

        self::assertCount(9, $config->allowedLicenses);
    }

    #[Test]
    public function customAllowlistOverridesDefaults(): void
    {
        $custom = ['MIT', 'Custom-1.0'];
        $config = new AllowedLicensesConfig($custom);

        self::assertSame($custom, $config->allowedLicenses);
    }

    #[Test]
    public function customAllowlistDoesNotContainDefaultEntries(): void
    {
        $config = new AllowedLicensesConfig(['Proprietary-1.0']);

        self::assertNotContains('MIT', $config->allowedLicenses);
        self::assertNotContains('Apache-2.0', $config->allowedLicenses);
        self::assertCount(1, $config->allowedLicenses);
    }

    #[Test]
    public function emptyArrayOverridesDefaultsToEmpty(): void
    {
        $config = new AllowedLicensesConfig([]);

        self::assertSame([], $config->allowedLicenses);
    }

    #[Test]
    public function nullUsesDefaults(): void
    {
        $config = new AllowedLicensesConfig(null);

        self::assertNotEmpty($config->allowedLicenses);
        self::assertContains('MIT', $config->allowedLicenses);
    }

    #[Test]
    public function defaultAllowlistDoesNotContainProprietaryLicenses(): void
    {
        $config = new AllowedLicensesConfig();

        self::assertNotContains('SSPL-1.0', $config->allowedLicenses);
        self::assertNotContains('BUSL-1.1', $config->allowedLicenses);
        self::assertNotContains('Commercial', $config->allowedLicenses);
    }

    #[Test]
    public function fromArrayReadsAllowedLicensesKey(): void
    {
        $config = AllowedLicensesConfig::fromArray([
            'allowed_licenses' => ['MIT', 'Proprietary-1.0'],
        ]);

        self::assertSame(['MIT', 'Proprietary-1.0'], $config->allowedLicenses);
    }

    #[Test]
    public function fromArrayFallsBackToDefaultsWhenKeyMissing(): void
    {
        $config = AllowedLicensesConfig::fromArray(['unrelated' => true]);

        self::assertContains('MIT', $config->allowedLicenses);
        self::assertCount(9, $config->allowedLicenses);
    }

    #[Test]
    public function fromArrayFallsBackToDefaultsWhenValueNotAnArray(): void
    {
        $config = AllowedLicensesConfig::fromArray(['allowed_licenses' => 'MIT']);

        self::assertContains('MIT', $config->allowedLicenses);
        self::assertCount(9, $config->allowedLicenses);
    }

    #[Test]
    public function fromArrayDropsNonStringEntriesAndReindexes(): void
    {
        $config = AllowedLicensesConfig::fromArray([
            'allowed_licenses' => ['MIT', 42, 'ISC', ['nested'], null],
        ]);

        self::assertSame(['MIT', 'ISC'], $config->allowedLicenses);
    }

    #[Test]
    public function fromArrayAcceptsEmptyAllowlist(): void
    {
        $config = AllowedLicensesConfig::fromArray(['allowed_licenses' => []]);

        self::assertSame([], $config->allowedLicenses);
    }
}
