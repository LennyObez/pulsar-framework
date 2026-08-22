<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Region;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Region\Continent;
use Pulsar\I18n\Region\Country;
use Pulsar\I18n\Region\CountryRegistry;

use function assert;
use function count;
use function strlen;

#[CoversClass(CountryRegistry::class)]
final class CountryRegistryTest extends TestCase
{
    private CountryRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new CountryRegistry();
    }

    #[Test]
    public function registryContainsExpectedCountryCount(): void
    {
        // At least 150 countries should be in the registry
        self::assertGreaterThanOrEqual(150, $this->registry->count());
    }

    #[Test]
    #[DataProvider('majorCountryProvider')]
    public function majorCountriesExistWithCorrectData(
        string $code,
        string $expectedName,
        string $expectedContinent,
        string $expectedCurrency,
    ): void {
        assert($code !== '');
        $country = $this->registry->get($code);

        self::assertNotNull($country, "Country {$code} should exist in registry");
        self::assertSame($expectedName, $country->name);
        self::assertSame($expectedContinent, $country->continent->value);
        self::assertSame($expectedCurrency, $country->currency);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function majorCountryProvider(): iterable
    {
        yield 'Belgium' => ['BE', 'Belgium', 'europe', 'EUR'];
        yield 'Netherlands' => ['NL', 'Netherlands', 'europe', 'EUR'];
        yield 'France' => ['FR', 'France', 'europe', 'EUR'];
        yield 'Germany' => ['DE', 'Germany', 'europe', 'EUR'];
        yield 'United Kingdom' => ['GB', 'United Kingdom', 'europe', 'GBP'];
        yield 'United States' => ['US', 'United States', 'north_america', 'USD'];
        yield 'Canada' => ['CA', 'Canada', 'north_america', 'CAD'];
        yield 'Japan' => ['JP', 'Japan', 'asia', 'JPY'];
        yield 'Australia' => ['AU', 'Australia', 'oceania', 'AUD'];
        yield 'Brazil' => ['BR', 'Brazil', 'south_america', 'BRL'];
        yield 'South Africa' => ['ZA', 'South Africa', 'africa', 'ZAR'];
        yield 'Switzerland' => ['CH', 'Switzerland', 'europe', 'CHF'];
        yield 'Spain' => ['ES', 'Spain', 'europe', 'EUR'];
        yield 'Italy' => ['IT', 'Italy', 'europe', 'EUR'];
        yield 'Poland' => ['PL', 'Poland', 'europe', 'PLN'];
        yield 'Sweden' => ['SE', 'Sweden', 'europe', 'SEK'];
        yield 'India' => ['IN', 'India', 'asia', 'INR'];
        yield 'Mexico' => ['MX', 'Mexico', 'north_america', 'MXN'];
    }

    #[Test]
    #[DataProvider('belgiumLanguageProvider')]
    public function belgiumHasAllFourLanguages(string $lang): void
    {
        $belgium = $this->registry->get('BE');

        self::assertNotNull($belgium);
        self::assertTrue($belgium->supportsLanguage($lang), "Belgium should support {$lang}");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function belgiumLanguageProvider(): iterable
    {
        yield 'Dutch' => ['nl'];
        yield 'French' => ['fr'];
        yield 'German' => ['de'];
        yield 'English' => ['en'];
    }

    #[Test]
    public function getIsCaseInsensitive(): void
    {
        $lower = $this->registry->get('be');
        $upper = $this->registry->get('BE');
        $mixed = $this->registry->get('Be');

        self::assertNotNull($lower);
        self::assertNotNull($upper);
        self::assertNotNull($mixed);
        self::assertSame($lower->code, $upper->code);
        self::assertSame($lower->code, $mixed->code);
    }

    #[Test]
    public function getReturnsNullForUnknownCode(): void
    {
        self::assertNull($this->registry->get('XX'));
        /** @phpstan-ignore argument.type (intentionally testing invalid empty-string input) */
        self::assertNull($this->registry->get(''));
        self::assertNull($this->registry->get('ZZ'));
    }

    #[Test]
    public function hasReturnsTrueForKnownCode(): void
    {
        self::assertTrue($this->registry->has('US'));
        self::assertTrue($this->registry->has('us'));
        self::assertTrue($this->registry->has('BE'));
    }

    #[Test]
    public function hasReturnsFalseForUnknownCode(): void
    {
        self::assertFalse($this->registry->has('XX'));
        self::assertFalse($this->registry->has(''));
    }

    #[Test]
    public function allReturnsSortedByName(): void
    {
        $all = $this->registry->all();

        self::assertNotEmpty($all);

        $names = array_map(static fn(Country $c): string => $c->name, $all);
        $sorted = $names;
        sort($sorted);

        self::assertSame($sorted, $names, 'Countries should be sorted alphabetically by name');
    }

    #[Test]
    public function byContinentReturnsOnlyMatchingContinent(): void
    {
        $european = $this->registry->byContinent(Continent::Europe);

        self::assertNotEmpty($european);

        foreach ($european as $country) {
            self::assertSame(Continent::Europe, $country->continent);
        }
    }

    #[Test]
    public function byContinentReturnsSortedByName(): void
    {
        $asian = $this->registry->byContinent(Continent::Asia);

        $names = array_map(static fn(Country $c): string => $c->name, $asian);
        $sorted = $names;
        sort($sorted);

        self::assertSame($sorted, $names);
    }

    #[Test]
    public function groupedByContinentReturnsAllPopulatedContinents(): void
    {
        $grouped = $this->registry->groupedByContinent();

        self::assertArrayHasKey('europe', $grouped);
        self::assertArrayHasKey('north_america', $grouped);
        self::assertArrayHasKey('south_america', $grouped);
        self::assertArrayHasKey('asia', $grouped);
        self::assertArrayHasKey('africa', $grouped);
        self::assertArrayHasKey('oceania', $grouped);
    }

    #[Test]
    public function groupedByContinentEuropeIsFirst(): void
    {
        $grouped = $this->registry->groupedByContinent();
        $keys = array_keys($grouped);

        self::assertSame('europe', $keys[0]);
    }

    #[Test]
    public function toArraySerializesCorrectly(): void
    {
        $array = $this->registry->toArray();

        self::assertArrayHasKey('europe', $array);

        $firstCountry = $array['europe'][0] ?? null;
        self::assertNotNull($firstCountry);
        self::assertArrayHasKey('code', $firstCountry);
        self::assertArrayHasKey('name', $firstCountry);
        self::assertArrayHasKey('continent', $firstCountry);
        self::assertArrayHasKey('languages', $firstCountry);
        self::assertArrayHasKey('currency', $firstCountry);
        self::assertArrayHasKey('flag', $firstCountry);
    }

    #[Test]
    public function registerAddsCustomCountry(): void
    {
        $custom = new Country(
            code: 'ZZ',
            name: 'Testland',
            continent: Continent::Europe,
            languages: ['en'],
            currency: 'TST',
            flag: 'ZZ',
        );

        $this->registry->register($custom);

        self::assertTrue($this->registry->has('ZZ'));
        self::assertSame('Testland', $this->registry->get('ZZ')?->name);
    }

    #[Test]
    public function allCountriesHaveAtLeastOneLanguage(): void
    {
        foreach ($this->registry->all() as $country) {
            self::assertNotEmpty(
                $country->languages,
                "Country {$country->code} ({$country->name}) must have at least one language",
            );
        }
    }

    #[Test]
    public function allCountriesHaveEnglishFallback(): void
    {
        foreach ($this->registry->all() as $country) {
            self::assertTrue(
                $country->supportsLanguage('en'),
                "Country {$country->code} ({$country->name}) should include English as a fallback",
            );
        }
    }

    #[Test]
    public function allCountriesHaveNonEmptyCurrencyCode(): void
    {
        foreach ($this->registry->all() as $country) {
            self::assertNotEmpty(
                $country->currency,
                "Country {$country->code} ({$country->name}) must have a currency code",
            );
            self::assertMatchesRegularExpression(
                '/\A[A-Z]{3}\z/',
                $country->currency,
                "Country {$country->code} currency should be a 3-letter ISO 4217 code, got '{$country->currency}'",
            );
        }
    }

    #[Test]
    public function allCountriesHaveValidIso31661Codes(): void
    {
        foreach ($this->registry->all() as $country) {
            self::assertMatchesRegularExpression(
                '/\A[A-Z]{2}\z/',
                $country->code,
                "Country code should be 2 uppercase letters, got '{$country->code}' for {$country->name}",
            );
        }
    }

    #[Test]
    public function allCountriesHaveFlagEmoji(): void
    {
        foreach ($this->registry->all() as $country) {
            self::assertNotEmpty(
                $country->flag,
                "Country {$country->code} ({$country->name}) must have a flag",
            );
            // Flag emojis are 4 bytes per regional indicator (2 indicators = 8 bytes)
            self::assertGreaterThanOrEqual(4, strlen($country->flag));
        }
    }

    #[Test]
    public function noCountryCodeDuplicates(): void
    {
        $all = $this->registry->all();
        $codes = array_map(static fn(Country $c): string => $c->code, $all);

        self::assertSame(
            count($codes),
            count(array_unique($codes)),
            'All country codes should be unique',
        );
    }

    #[Test]
    public function lazyInitializationWorksOnMultipleCalls(): void
    {
        // First call triggers init
        $count1 = $this->registry->count();
        // Second call should return same count without re-init
        $count2 = $this->registry->count();

        self::assertSame($count1, $count2);
    }

    #[Test]
    #[DataProvider('euCountriesHaveEurProvider')]
    public function eurozoneMembersUseEur(string $code): void
    {
        assert($code !== '');
        $country = $this->registry->get($code);

        self::assertNotNull($country, "Country {$code} should exist");
        self::assertSame('EUR', $country->currency, "Country {$code} should use EUR");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function euCountriesHaveEurProvider(): iterable
    {
        yield 'Austria' => ['AT'];
        yield 'Belgium' => ['BE'];
        yield 'Croatia' => ['HR'];
        yield 'Estonia' => ['EE'];
        yield 'Finland' => ['FI'];
        yield 'France' => ['FR'];
        yield 'Germany' => ['DE'];
        yield 'Greece' => ['GR'];
        yield 'Ireland' => ['IE'];
        yield 'Italy' => ['IT'];
        yield 'Latvia' => ['LV'];
        yield 'Lithuania' => ['LT'];
        yield 'Luxembourg' => ['LU'];
        yield 'Malta' => ['MT'];
        yield 'Netherlands' => ['NL'];
        yield 'Portugal' => ['PT'];
        yield 'Slovakia' => ['SK'];
        yield 'Slovenia' => ['SI'];
        yield 'Spain' => ['ES'];
    }
}
