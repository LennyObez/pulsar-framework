<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Region;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Region\Continent;
use Pulsar\I18n\Region\Country;
use ReflectionClass;

#[CoversClass(Country::class)]
final class CountryTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $country = new Country(
            code: 'BE',
            name: 'Belgium',
            continent: Continent::Europe,
            languages: ['nl', 'fr', 'de', 'en'],
            currency: 'EUR',
            flag: "\u{1F1E7}\u{1F1EA}",
        );

        self::assertSame('BE', $country->code);
        self::assertSame('Belgium', $country->name);
        self::assertSame(Continent::Europe, $country->continent);
        self::assertSame(['nl', 'fr', 'de', 'en'], $country->languages);
        self::assertSame('EUR', $country->currency);
        self::assertSame("\u{1F1E7}\u{1F1EA}", $country->flag);
    }

    #[Test]
    #[DataProvider('languageSupportProvider')]
    public function supportsLanguageChecksCorrectly(string $lang, bool $expected): void
    {
        $country = $this->createBelgium();

        self::assertSame($expected, $country->supportsLanguage($lang));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function languageSupportProvider(): iterable
    {
        yield 'Dutch supported' => ['nl', true];
        yield 'French supported' => ['fr', true];
        yield 'German supported' => ['de', true];
        yield 'English supported' => ['en', true];
        yield 'Spanish not supported' => ['es', false];
        yield 'Empty string not supported' => ['', false];
        yield 'Random string not supported' => ['xyz', false];
    }

    #[Test]
    public function primaryLanguageReturnsFirstElement(): void
    {
        $country = $this->createBelgium();

        self::assertSame('nl', $country->primaryLanguage());
    }

    #[Test]
    public function primaryLanguageWithSingleLanguage(): void
    {
        $country = new Country(
            code: 'US',
            name: 'United States',
            continent: Continent::NorthAmerica,
            languages: ['en'],
            currency: 'USD',
            flag: "\u{1F1FA}\u{1F1F8}",
        );

        self::assertSame('en', $country->primaryLanguage());
    }

    #[Test]
    public function toArrayReturnsCorrectStructure(): void
    {
        $country = $this->createBelgium();
        $array = $country->toArray();

        self::assertSame('BE', $array['code']);
        self::assertSame('Belgium', $array['name']);
        self::assertSame('europe', $array['continent']);
        self::assertSame(['nl', 'fr', 'de', 'en'], $array['languages']);
        self::assertSame('EUR', $array['currency']);
        self::assertSame("\u{1F1E7}\u{1F1EA}", $array['flag']);
    }

    #[Test]
    public function toArrayContainsExactlySixKeys(): void
    {
        $array = $this->createBelgium()->toArray();

        self::assertCount(6, $array);
        self::assertArrayHasKey('code', $array);
        self::assertArrayHasKey('name', $array);
        self::assertArrayHasKey('continent', $array);
        self::assertArrayHasKey('languages', $array);
        self::assertArrayHasKey('currency', $array);
        self::assertArrayHasKey('flag', $array);
    }

    #[Test]
    public function readonlyPropertiesCannotBeModified(): void
    {
        $country = $this->createBelgium();

        $reflection = new ReflectionClass($country);

        self::assertTrue($reflection->isReadonly());
    }

    private function createBelgium(): Country
    {
        return new Country(
            code: 'BE',
            name: 'Belgium',
            continent: Continent::Europe,
            languages: ['nl', 'fr', 'de', 'en'],
            currency: 'EUR',
            flag: "\u{1F1E7}\u{1F1EA}",
        );
    }
}
