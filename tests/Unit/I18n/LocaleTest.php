<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Locale;

#[CoversClass(Locale::class)]
final class LocaleTest extends TestCase
{
    #[Test]
    public function parseSimpleLanguage(): void
    {
        $locale = Locale::parse('en');

        self::assertSame('en', $locale->tag);
        self::assertSame('en', $locale->language);
        self::assertNull($locale->region);
    }

    #[Test]
    public function parseLanguageWithRegionUnderscore(): void
    {
        $locale = Locale::parse('fr_CA');

        self::assertSame('fr_CA', $locale->tag);
        self::assertSame('fr', $locale->language);
        self::assertSame('CA', $locale->region);
    }

    #[Test]
    public function parseLanguageWithRegionHyphen(): void
    {
        $locale = Locale::parse('fr-CA');

        self::assertSame('fr_CA', $locale->tag);
        self::assertSame('fr', $locale->language);
        self::assertSame('CA', $locale->region);
    }

    #[Test]
    public function parseNormalizesLanguageToLowercase(): void
    {
        $locale = Locale::parse('EN');

        self::assertSame('EN', $locale->tag);
        self::assertSame('en', $locale->language);
    }

    #[Test]
    public function fallbackChainWithRegion(): void
    {
        $locale = Locale::parse('fr_CA');

        self::assertSame(['fr_CA', 'fr'], $locale->fallbackChain());
    }

    #[Test]
    public function fallbackChainWithoutRegion(): void
    {
        $locale = Locale::parse('fr');

        self::assertSame(['fr'], $locale->fallbackChain());
    }

    #[Test]
    public function isRtlForArabic(): void
    {
        self::assertTrue(Locale::parse('ar')->isRtl());
        self::assertTrue(Locale::parse('ar_SA')->isRtl());
    }

    #[Test]
    public function isRtlForHebrew(): void
    {
        self::assertTrue(Locale::parse('he')->isRtl());
    }

    #[Test]
    public function isRtlForFarsi(): void
    {
        self::assertTrue(Locale::parse('fa')->isRtl());
    }

    #[Test]
    public function isRtlForUrdu(): void
    {
        self::assertTrue(Locale::parse('ur')->isRtl());
    }

    #[Test]
    public function isNotRtlForLtrLanguages(): void
    {
        self::assertFalse(Locale::parse('en')->isRtl());
        self::assertFalse(Locale::parse('fr')->isRtl());
        self::assertFalse(Locale::parse('de')->isRtl());
        self::assertFalse(Locale::parse('zh')->isRtl());
    }
}
