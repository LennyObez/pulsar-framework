<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media\License;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Media\License\LicenseType;

#[CoversNothing]
final class LicenseTypeTest extends TestCase
{
    #[Test]
    #[DataProvider('allLicensesProvider')]
    public function every_license_has_a_label(LicenseType $license): void
    {
        $label = $license->label();

        self::assertNotEmpty($label);
        self::assertIsString($label);
    }

    #[Test]
    #[DataProvider('creativCommonsProvider')]
    public function creative_commons_licenses_have_urls(LicenseType $license): void
    {
        self::assertNotNull($license->url());
        self::assertStringStartsWith('https://creativecommons.org/', $license->url() ?? '');
        self::assertTrue($license->isCreativeCommons());
    }

    #[Test]
    public function all_rights_reserved_has_no_url(): void
    {
        self::assertNull(LicenseType::AllRightsReserved->url());
        self::assertFalse(LicenseType::AllRightsReserved->isCreativeCommons());
    }

    #[Test]
    public function custom_has_no_url(): void
    {
        self::assertNull(LicenseType::Custom->url());
        self::assertFalse(LicenseType::Custom->isCreativeCommons());
    }

    #[Test]
    public function tryFrom_parses_valid_string(): void
    {
        $license = LicenseType::tryFrom('CC-BY');

        self::assertSame(LicenseType::CcBy, $license);
    }

    #[Test]
    public function tryFrom_returns_null_for_invalid_string(): void
    {
        self::assertNull(LicenseType::tryFrom('INVALID'));
    }

    #[Test]
    public function cc_zero_has_public_domain_url(): void
    {
        self::assertSame(
            'https://creativecommons.org/publicdomain/zero/1.0/',
            LicenseType::CcZero->url(),
        );
        self::assertTrue(LicenseType::CcZero->isCreativeCommons());
    }

    #[Test]
    #[DataProvider('valueLabelPairProvider')]
    public function value_matches_expected_label(string $value, string $expectedLabelSubstring): void
    {
        $license = LicenseType::from($value);

        self::assertStringContainsString($expectedLabelSubstring, $license->label());
    }

    /**
     * @return iterable<string, array{LicenseType}>
     */
    public static function allLicensesProvider(): iterable
    {
        foreach (LicenseType::cases() as $case) {
            yield $case->value => [$case];
        }
    }

    /**
     * @return iterable<string, array{LicenseType}>
     */
    public static function creativCommonsProvider(): iterable
    {
        yield 'CC-BY' => [LicenseType::CcBy];
        yield 'CC-BY-SA' => [LicenseType::CcBySa];
        yield 'CC-BY-NC' => [LicenseType::CcByNc];
        yield 'CC-BY-NC-SA' => [LicenseType::CcByNcSa];
        yield 'CC-BY-ND' => [LicenseType::CcByNd];
        yield 'CC-BY-NC-ND' => [LicenseType::CcByNcNd];
        yield 'CC0' => [LicenseType::CcZero];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valueLabelPairProvider(): iterable
    {
        yield 'CC-BY' => ['CC-BY', 'Attribution'];
        yield 'CC-BY-SA' => ['CC-BY-SA', 'ShareAlike'];
        yield 'CC-BY-NC' => ['CC-BY-NC', 'NonCommercial'];
        yield 'CC-BY-ND' => ['CC-BY-ND', 'NoDerivatives'];
        yield 'All Rights Reserved' => ['All Rights Reserved', 'All Rights Reserved'];
        yield 'Custom' => ['Custom', 'Custom'];
    }
}
