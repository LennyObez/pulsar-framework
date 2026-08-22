<?php

declare(strict_types=1);

namespace Pulsar\I18n\Region;

use NoDiscard;
use Pulsar\Api\Api;

use function array_filter;
use function array_values;
use function assert;
use function chr;
use function count;
use function ord;
use function strtoupper;
use function usort;

/**
 * Registry of all countries with their regional metadata.
 *
 * Provides lookup by ISO 3166-1 alpha-2 code, filtering by continent,
 * and listing grouped by continent for the region selector UI.
 *
 * Language lists per country represent the national/official languages
 * the framework has translations for, plus English as a universal fallback.
 * Countries without any supported national language show English only.
 * @api
 */
#[Api(since: '1.0.0')]
final class CountryRegistry
{
    /** @var array<string, Country> Indexed by uppercase ISO alpha-2 code */
    private array $countries = [];

    private bool $initialized = false;

    /**
     * Get a country by its ISO 3166-1 alpha-2 code.
     *
     * @param non-empty-string $code ISO alpha-2 code (case-insensitive)
     */
    #[NoDiscard]
    public function get(string $code): ?Country
    {
        $this->ensureInitialized();

        return $this->countries[strtoupper($code)] ?? null;
    }

    /**
     * Whether a country code is registered.
     */
    #[NoDiscard]
    public function has(string $code): bool
    {
        $this->ensureInitialized();

        return isset($this->countries[strtoupper($code)]);
    }

    /**
     * Get all registered countries, sorted alphabetically by name.
     *
     * @return list<Country>
     */
    #[NoDiscard]
    public function all(): array
    {
        $this->ensureInitialized();

        $countries = array_values($this->countries);

        usort($countries, static fn(Country $a, Country $b): int => $a->name <=> $b->name);

        return $countries;
    }

    /**
     * Get countries filtered by continent, sorted alphabetically.
     *
     * @return list<Country>
     */
    #[NoDiscard]
    public function byContinent(Continent $continent): array
    {
        $this->ensureInitialized();

        $filtered = array_filter(
            $this->countries,
            static fn(Country $c): bool => $c->continent === $continent,
        );

        $result = array_values($filtered);

        usort($result, static fn(Country $a, Country $b): int => $a->name <=> $b->name);

        return $result;
    }

    /**
     * Get all countries grouped by continent, sorted by continent display order.
     *
     * @return array<string, list<Country>> Keyed by continent value
     */
    #[NoDiscard]
    public function groupedByContinent(): array
    {
        $this->ensureInitialized();

        $continents = Continent::cases();

        usort($continents, static fn(Continent $a, Continent $b): int => $a->sortOrder() <=> $b->sortOrder());

        $grouped = [];

        foreach ($continents as $continent) {
            $countries = $this->byContinent($continent);

            if ($countries !== []) {
                $grouped[$continent->value] = $countries;
            }
        }

        return $grouped;
    }

    /**
     * Serialize the full registry to an array for JSON API responses.
     *
     * @return array<string, list<array{code: string, name: string, continent: string, languages: list<string>, currency: string, flag: string}>>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        $grouped = $this->groupedByContinent();
        $result = [];

        foreach ($grouped as $continentValue => $countries) {
            $result[$continentValue] = [];

            foreach ($countries as $country) {
                $result[$continentValue][] = $country->toArray();
            }
        }

        return $result;
    }

    /**
     * Register a country. Allows extensions to add custom entries.
     */
    public function register(Country $country): void
    {
        $this->ensureInitialized();
        $this->countries[strtoupper($country->code)] = $country;
    }

    /**
     * Total number of registered countries.
     */
    #[NoDiscard]
    public function count(): int
    {
        $this->ensureInitialized();

        return count($this->countries);
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;
        $this->loadCountries();
    }

    private function loadCountries(): void
    {
        // --- Europe ---
        $this->add('AL', 'Albania', Continent::Europe, ['sq', 'en'], 'ALL');
        $this->add('AD', 'Andorra', Continent::Europe, ['ca', 'en'], 'EUR');
        $this->add('AT', 'Austria', Continent::Europe, ['de', 'en'], 'EUR');
        $this->add('BY', 'Belarus', Continent::Europe, ['en'], 'BYN');
        $this->add('BE', 'Belgium', Continent::Europe, ['nl', 'fr', 'de', 'en'], 'EUR');
        $this->add('BA', 'Bosnia and Herzegovina', Continent::Europe, ['en'], 'BAM');
        $this->add('BG', 'Bulgaria', Continent::Europe, ['bg', 'en'], 'BGN');
        $this->add('HR', 'Croatia', Continent::Europe, ['hr', 'en'], 'EUR');
        $this->add('CY', 'Cyprus', Continent::Europe, ['el', 'en'], 'EUR');
        $this->add('CZ', 'Czechia', Continent::Europe, ['cs', 'en'], 'CZK');
        $this->add('DK', 'Denmark', Continent::Europe, ['da', 'en'], 'DKK');
        $this->add('EE', 'Estonia', Continent::Europe, ['et', 'en'], 'EUR');
        $this->add('FI', 'Finland', Continent::Europe, ['fi', 'sv', 'en'], 'EUR');
        $this->add('FR', 'France', Continent::Europe, ['fr', 'en'], 'EUR');
        $this->add('DE', 'Germany', Continent::Europe, ['de', 'en'], 'EUR');
        $this->add('GR', 'Greece', Continent::Europe, ['el', 'en'], 'EUR');
        $this->add('HU', 'Hungary', Continent::Europe, ['hu', 'en'], 'HUF');
        $this->add('IS', 'Iceland', Continent::Europe, ['en'], 'ISK');
        $this->add('IE', 'Ireland', Continent::Europe, ['en', 'ga'], 'EUR');
        $this->add('IT', 'Italy', Continent::Europe, ['it', 'en'], 'EUR');
        $this->add('XK', 'Kosovo', Continent::Europe, ['sq', 'en'], 'EUR');
        $this->add('LV', 'Latvia', Continent::Europe, ['lv', 'en'], 'EUR');
        $this->add('LI', 'Liechtenstein', Continent::Europe, ['de', 'en'], 'CHF');
        $this->add('LT', 'Lithuania', Continent::Europe, ['lt', 'en'], 'EUR');
        $this->add('LU', 'Luxembourg', Continent::Europe, ['lb', 'fr', 'de', 'en'], 'EUR');
        $this->add('MT', 'Malta', Continent::Europe, ['mt', 'en'], 'EUR');
        $this->add('MD', 'Moldova', Continent::Europe, ['ro', 'en'], 'MDL');
        $this->add('MC', 'Monaco', Continent::Europe, ['fr', 'en'], 'EUR');
        $this->add('ME', 'Montenegro', Continent::Europe, ['en'], 'EUR');
        $this->add('NL', 'Netherlands', Continent::Europe, ['nl', 'en'], 'EUR');
        $this->add('MK', 'North Macedonia', Continent::Europe, ['en'], 'MKD');
        $this->add('NO', 'Norway', Continent::Europe, ['en'], 'NOK');
        $this->add('PL', 'Poland', Continent::Europe, ['pl', 'en'], 'PLN');
        $this->add('PT', 'Portugal', Continent::Europe, ['pt', 'en'], 'EUR');
        $this->add('RO', 'Romania', Continent::Europe, ['ro', 'en'], 'RON');
        $this->add('RU', 'Russia', Continent::Europe, ['en'], 'RUB');
        $this->add('SM', 'San Marino', Continent::Europe, ['it', 'en'], 'EUR');
        $this->add('RS', 'Serbia', Continent::Europe, ['en'], 'RSD');
        $this->add('SK', 'Slovakia', Continent::Europe, ['sk', 'en'], 'EUR');
        $this->add('SI', 'Slovenia', Continent::Europe, ['sl', 'en'], 'EUR');
        $this->add('ES', 'Spain', Continent::Europe, ['es', 'en'], 'EUR');
        $this->add('SE', 'Sweden', Continent::Europe, ['sv', 'en'], 'SEK');
        $this->add('CH', 'Switzerland', Continent::Europe, ['de', 'fr', 'it', 'en'], 'CHF');
        $this->add('UA', 'Ukraine', Continent::Europe, ['en'], 'UAH');
        $this->add('GB', 'United Kingdom', Continent::Europe, ['en'], 'GBP');
        $this->add('VA', 'Vatican City', Continent::Europe, ['it', 'en'], 'EUR');

        // --- North America ---
        $this->add('AG', 'Antigua and Barbuda', Continent::NorthAmerica, ['en'], 'XCD');
        $this->add('BS', 'Bahamas', Continent::NorthAmerica, ['en'], 'BSD');
        $this->add('BB', 'Barbados', Continent::NorthAmerica, ['en'], 'BBD');
        $this->add('BZ', 'Belize', Continent::NorthAmerica, ['en', 'es'], 'BZD');
        $this->add('CA', 'Canada', Continent::NorthAmerica, ['en', 'fr'], 'CAD');
        $this->add('CR', 'Costa Rica', Continent::NorthAmerica, ['es', 'en'], 'CRC');
        $this->add('CU', 'Cuba', Continent::NorthAmerica, ['es', 'en'], 'CUP');
        $this->add('DM', 'Dominica', Continent::NorthAmerica, ['en'], 'XCD');
        $this->add('DO', 'Dominican Republic', Continent::NorthAmerica, ['es', 'en'], 'DOP');
        $this->add('SV', 'El Salvador', Continent::NorthAmerica, ['es', 'en'], 'USD');
        $this->add('GD', 'Grenada', Continent::NorthAmerica, ['en'], 'XCD');
        $this->add('GT', 'Guatemala', Continent::NorthAmerica, ['es', 'en'], 'GTQ');
        $this->add('HT', 'Haiti', Continent::NorthAmerica, ['fr', 'en'], 'HTG');
        $this->add('HN', 'Honduras', Continent::NorthAmerica, ['es', 'en'], 'HNL');
        $this->add('JM', 'Jamaica', Continent::NorthAmerica, ['en'], 'JMD');
        $this->add('MX', 'Mexico', Continent::NorthAmerica, ['es', 'en'], 'MXN');
        $this->add('NI', 'Nicaragua', Continent::NorthAmerica, ['es', 'en'], 'NIO');
        $this->add('PA', 'Panama', Continent::NorthAmerica, ['es', 'en'], 'PAB');
        $this->add('KN', 'Saint Kitts and Nevis', Continent::NorthAmerica, ['en'], 'XCD');
        $this->add('LC', 'Saint Lucia', Continent::NorthAmerica, ['en'], 'XCD');
        $this->add('VC', 'Saint Vincent and the Grenadines', Continent::NorthAmerica, ['en'], 'XCD');
        $this->add('TT', 'Trinidad and Tobago', Continent::NorthAmerica, ['en'], 'TTD');
        $this->add('US', 'United States', Continent::NorthAmerica, ['en', 'es'], 'USD');

        // --- South America ---
        $this->add('AR', 'Argentina', Continent::SouthAmerica, ['es', 'en'], 'ARS');
        $this->add('BO', 'Bolivia', Continent::SouthAmerica, ['es', 'en'], 'BOB');
        $this->add('BR', 'Brazil', Continent::SouthAmerica, ['pt', 'en'], 'BRL');
        $this->add('CL', 'Chile', Continent::SouthAmerica, ['es', 'en'], 'CLP');
        $this->add('CO', 'Colombia', Continent::SouthAmerica, ['es', 'en'], 'COP');
        $this->add('EC', 'Ecuador', Continent::SouthAmerica, ['es', 'en'], 'USD');
        $this->add('GY', 'Guyana', Continent::SouthAmerica, ['en'], 'GYD');
        $this->add('PY', 'Paraguay', Continent::SouthAmerica, ['es', 'en'], 'PYG');
        $this->add('PE', 'Peru', Continent::SouthAmerica, ['es', 'en'], 'PEN');
        $this->add('SR', 'Suriname', Continent::SouthAmerica, ['nl', 'en'], 'SRD');
        $this->add('UY', 'Uruguay', Continent::SouthAmerica, ['es', 'en'], 'UYU');
        $this->add('VE', 'Venezuela', Continent::SouthAmerica, ['es', 'en'], 'VES');

        // --- Asia ---
        $this->add('AF', 'Afghanistan', Continent::Asia, ['en'], 'AFN');
        $this->add('AM', 'Armenia', Continent::Asia, ['en'], 'AMD');
        $this->add('AZ', 'Azerbaijan', Continent::Asia, ['en'], 'AZN');
        $this->add('BH', 'Bahrain', Continent::Asia, ['ar', 'en'], 'BHD');
        $this->add('BD', 'Bangladesh', Continent::Asia, ['en'], 'BDT');
        $this->add('BT', 'Bhutan', Continent::Asia, ['en'], 'BTN');
        $this->add('BN', 'Brunei', Continent::Asia, ['en'], 'BND');
        $this->add('KH', 'Cambodia', Continent::Asia, ['en'], 'KHR');
        $this->add('CN', 'China', Continent::Asia, ['en'], 'CNY');
        $this->add('GE', 'Georgia', Continent::Asia, ['en'], 'GEL');
        $this->add('IN', 'India', Continent::Asia, ['en'], 'INR');
        $this->add('ID', 'Indonesia', Continent::Asia, ['en'], 'IDR');
        $this->add('IR', 'Iran', Continent::Asia, ['fa', 'en'], 'IRR');
        $this->add('IQ', 'Iraq', Continent::Asia, ['ar', 'en'], 'IQD');
        $this->add('IL', 'Israel', Continent::Asia, ['he', 'en'], 'ILS');
        $this->add('JP', 'Japan', Continent::Asia, ['en'], 'JPY');
        $this->add('JO', 'Jordan', Continent::Asia, ['ar', 'en'], 'JOD');
        $this->add('KZ', 'Kazakhstan', Continent::Asia, ['en'], 'KZT');
        $this->add('KW', 'Kuwait', Continent::Asia, ['ar', 'en'], 'KWD');
        $this->add('KG', 'Kyrgyzstan', Continent::Asia, ['en'], 'KGS');
        $this->add('LA', 'Laos', Continent::Asia, ['en'], 'LAK');
        $this->add('LB', 'Lebanon', Continent::Asia, ['ar', 'en'], 'LBP');
        $this->add('MY', 'Malaysia', Continent::Asia, ['en'], 'MYR');
        $this->add('MV', 'Maldives', Continent::Asia, ['en'], 'MVR');
        $this->add('MN', 'Mongolia', Continent::Asia, ['en'], 'MNT');
        $this->add('MM', 'Myanmar', Continent::Asia, ['en'], 'MMK');
        $this->add('NP', 'Nepal', Continent::Asia, ['en'], 'NPR');
        $this->add('KP', 'North Korea', Continent::Asia, ['en'], 'KPW');
        $this->add('OM', 'Oman', Continent::Asia, ['ar', 'en'], 'OMR');
        $this->add('PK', 'Pakistan', Continent::Asia, ['ur', 'en'], 'PKR');
        $this->add('PS', 'Palestine', Continent::Asia, ['ar', 'en'], 'ILS');
        $this->add('PH', 'Philippines', Continent::Asia, ['en'], 'PHP');
        $this->add('QA', 'Qatar', Continent::Asia, ['ar', 'en'], 'QAR');
        $this->add('SA', 'Saudi Arabia', Continent::Asia, ['ar', 'en'], 'SAR');
        $this->add('SG', 'Singapore', Continent::Asia, ['en'], 'SGD');
        $this->add('KR', 'South Korea', Continent::Asia, ['en'], 'KRW');
        $this->add('LK', 'Sri Lanka', Continent::Asia, ['en'], 'LKR');
        $this->add('SY', 'Syria', Continent::Asia, ['ar', 'en'], 'SYP');
        $this->add('TW', 'Taiwan', Continent::Asia, ['en'], 'TWD');
        $this->add('TJ', 'Tajikistan', Continent::Asia, ['en'], 'TJS');
        $this->add('TH', 'Thailand', Continent::Asia, ['en'], 'THB');
        $this->add('TL', 'Timor-Leste', Continent::Asia, ['pt', 'en'], 'USD');
        $this->add('TR', 'Turkey', Continent::Asia, ['en'], 'TRY');
        $this->add('TM', 'Turkmenistan', Continent::Asia, ['en'], 'TMT');
        $this->add('AE', 'United Arab Emirates', Continent::Asia, ['ar', 'en'], 'AED');
        $this->add('UZ', 'Uzbekistan', Continent::Asia, ['en'], 'UZS');
        $this->add('VN', 'Vietnam', Continent::Asia, ['en'], 'VND');
        $this->add('YE', 'Yemen', Continent::Asia, ['ar', 'en'], 'YER');

        // --- Africa ---
        $this->add('DZ', 'Algeria', Continent::Africa, ['ar', 'fr', 'en'], 'DZD');
        $this->add('AO', 'Angola', Continent::Africa, ['pt', 'en'], 'AOA');
        $this->add('BJ', 'Benin', Continent::Africa, ['fr', 'en'], 'XOF');
        $this->add('BW', 'Botswana', Continent::Africa, ['en'], 'BWP');
        $this->add('BF', 'Burkina Faso', Continent::Africa, ['fr', 'en'], 'XOF');
        $this->add('BI', 'Burundi', Continent::Africa, ['fr', 'en'], 'BIF');
        $this->add('CV', 'Cabo Verde', Continent::Africa, ['pt', 'en'], 'CVE');
        $this->add('CM', 'Cameroon', Continent::Africa, ['fr', 'en'], 'XAF');
        $this->add('CF', 'Central African Republic', Continent::Africa, ['fr', 'en'], 'XAF');
        $this->add('TD', 'Chad', Continent::Africa, ['fr', 'ar', 'en'], 'XAF');
        $this->add('KM', 'Comoros', Continent::Africa, ['fr', 'ar', 'en'], 'KMF');
        $this->add('CD', 'Congo (DRC)', Continent::Africa, ['fr', 'en'], 'CDF');
        $this->add('CG', 'Congo (Republic)', Continent::Africa, ['fr', 'en'], 'XAF');
        $this->add('CI', 'Cote d\'Ivoire', Continent::Africa, ['fr', 'en'], 'XOF');
        $this->add('DJ', 'Djibouti', Continent::Africa, ['fr', 'ar', 'en'], 'DJF');
        $this->add('EG', 'Egypt', Continent::Africa, ['ar', 'en'], 'EGP');
        $this->add('GQ', 'Equatorial Guinea', Continent::Africa, ['es', 'fr', 'en'], 'XAF');
        $this->add('ER', 'Eritrea', Continent::Africa, ['en'], 'ERN');
        $this->add('SZ', 'Eswatini', Continent::Africa, ['en'], 'SZL');
        $this->add('ET', 'Ethiopia', Continent::Africa, ['en'], 'ETB');
        $this->add('GA', 'Gabon', Continent::Africa, ['fr', 'en'], 'XAF');
        $this->add('GM', 'Gambia', Continent::Africa, ['en'], 'GMD');
        $this->add('GH', 'Ghana', Continent::Africa, ['en'], 'GHS');
        $this->add('GN', 'Guinea', Continent::Africa, ['fr', 'en'], 'GNF');
        $this->add('GW', 'Guinea-Bissau', Continent::Africa, ['pt', 'en'], 'XOF');
        $this->add('KE', 'Kenya', Continent::Africa, ['en'], 'KES');
        $this->add('LS', 'Lesotho', Continent::Africa, ['en'], 'LSL');
        $this->add('LR', 'Liberia', Continent::Africa, ['en'], 'LRD');
        $this->add('LY', 'Libya', Continent::Africa, ['ar', 'en'], 'LYD');
        $this->add('MG', 'Madagascar', Continent::Africa, ['fr', 'en'], 'MGA');
        $this->add('MW', 'Malawi', Continent::Africa, ['en'], 'MWK');
        $this->add('ML', 'Mali', Continent::Africa, ['fr', 'en'], 'XOF');
        $this->add('MR', 'Mauritania', Continent::Africa, ['ar', 'fr', 'en'], 'MRU');
        $this->add('MU', 'Mauritius', Continent::Africa, ['en', 'fr'], 'MUR');
        $this->add('MA', 'Morocco', Continent::Africa, ['ar', 'fr', 'en'], 'MAD');
        $this->add('MZ', 'Mozambique', Continent::Africa, ['pt', 'en'], 'MZN');
        $this->add('NA', 'Namibia', Continent::Africa, ['en'], 'NAD');
        $this->add('NE', 'Niger', Continent::Africa, ['fr', 'en'], 'XOF');
        $this->add('NG', 'Nigeria', Continent::Africa, ['en'], 'NGN');
        $this->add('RW', 'Rwanda', Continent::Africa, ['en', 'fr'], 'RWF');
        $this->add('ST', 'Sao Tome and Principe', Continent::Africa, ['pt', 'en'], 'STN');
        $this->add('SN', 'Senegal', Continent::Africa, ['fr', 'en'], 'XOF');
        $this->add('SC', 'Seychelles', Continent::Africa, ['en', 'fr'], 'SCR');
        $this->add('SL', 'Sierra Leone', Continent::Africa, ['en'], 'SLE');
        $this->add('SO', 'Somalia', Continent::Africa, ['en'], 'SOS');
        $this->add('ZA', 'South Africa', Continent::Africa, ['en'], 'ZAR');
        $this->add('SS', 'South Sudan', Continent::Africa, ['en'], 'SSP');
        $this->add('SD', 'Sudan', Continent::Africa, ['ar', 'en'], 'SDG');
        $this->add('TZ', 'Tanzania', Continent::Africa, ['en'], 'TZS');
        $this->add('TG', 'Togo', Continent::Africa, ['fr', 'en'], 'XOF');
        $this->add('TN', 'Tunisia', Continent::Africa, ['ar', 'fr', 'en'], 'TND');
        $this->add('UG', 'Uganda', Continent::Africa, ['en'], 'UGX');
        $this->add('ZM', 'Zambia', Continent::Africa, ['en'], 'ZMW');
        $this->add('ZW', 'Zimbabwe', Continent::Africa, ['en'], 'ZWL');

        // --- Oceania ---
        $this->add('AU', 'Australia', Continent::Oceania, ['en'], 'AUD');
        $this->add('FJ', 'Fiji', Continent::Oceania, ['en'], 'FJD');
        $this->add('KI', 'Kiribati', Continent::Oceania, ['en'], 'AUD');
        $this->add('MH', 'Marshall Islands', Continent::Oceania, ['en'], 'USD');
        $this->add('FM', 'Micronesia', Continent::Oceania, ['en'], 'USD');
        $this->add('NR', 'Nauru', Continent::Oceania, ['en'], 'AUD');
        $this->add('NZ', 'New Zealand', Continent::Oceania, ['en'], 'NZD');
        $this->add('PW', 'Palau', Continent::Oceania, ['en'], 'USD');
        $this->add('PG', 'Papua New Guinea', Continent::Oceania, ['en'], 'PGK');
        $this->add('WS', 'Samoa', Continent::Oceania, ['en'], 'WST');
        $this->add('SB', 'Solomon Islands', Continent::Oceania, ['en'], 'SBD');
        $this->add('TO', 'Tonga', Continent::Oceania, ['en'], 'TOP');
        $this->add('TV', 'Tuvalu', Continent::Oceania, ['en'], 'AUD');
        $this->add('VU', 'Vanuatu', Continent::Oceania, ['en', 'fr'], 'VUV');
    }

    /**
     * @param non-empty-string $code
     * @param non-empty-string $name
     * @param list<non-empty-string> $languages
     * @param non-empty-string $currency
     */
    private function add(string $code, string $name, Continent $continent, array $languages, string $currency): void
    {
        $this->countries[strtoupper($code)] = new Country(
            code: $code,
            name: $name,
            continent: $continent,
            languages: $languages,
            currency: $currency,
            flag: self::codeToFlag($code),
        );
    }

    /**
     * Convert an ISO alpha-2 code to its flag emoji using regional indicator symbols.
     *
     * Uses UTF-8 encoding directly: no ext-intl dependency.
     * Regional indicator symbol A is U+1F1E6. Each letter offset maps to
     * the corresponding regional indicator: A=0, B=1, ..., Z=25.
     */
    /**
     * @return non-empty-string
     */
    private static function codeToFlag(string $code): string
    {
        $upper = strtoupper($code);

        // Regional indicator symbol base: U+1F1E6 = A
        $cp1 = 0x1F1E6 + ord($upper[0]) - ord('A');
        $cp2 = 0x1F1E6 + ord($upper[1]) - ord('A');

        $flag = self::utf8Encode($cp1) . self::utf8Encode($cp2);
        assert($flag !== '');

        return $flag;
    }

    /**
     * Encode a Unicode code point to a UTF-8 string without ext-intl.
     */
    private static function utf8Encode(int $codepoint): string
    {
        if ($codepoint <= 0x7F) {
            return chr($codepoint & 0xFF);
        }

        if ($codepoint <= 0x7FF) {
            return chr((0xC0 | ($codepoint >> 6)) & 0xFF)
                 . chr((0x80 | ($codepoint & 0x3F)) & 0xFF);
        }

        if ($codepoint <= 0xFFFF) {
            return chr((0xE0 | ($codepoint >> 12)) & 0xFF)
                 . chr((0x80 | (($codepoint >> 6) & 0x3F)) & 0xFF)
                 . chr((0x80 | ($codepoint & 0x3F)) & 0xFF);
        }

        return chr((0xF0 | ($codepoint >> 18)) & 0xFF)
             . chr((0x80 | (($codepoint >> 12) & 0x3F)) & 0xFF)
             . chr((0x80 | (($codepoint >> 6) & 0x3F)) & 0xFF)
             . chr((0x80 | ($codepoint & 0x3F)) & 0xFF);
    }
}
