<?php

declare(strict_types=1);

namespace Pulsar\I18n\Region;

use NoDiscard;
use Pulsar\Api\Api;

use function in_array;

/**
 * Immutable value object representing a country with its regional metadata.
 *
 * Each country carries its ISO 3166-1 alpha-2 code, human-readable name,
 * continent classification, supported languages, default currency (ISO 4217),
 * and flag emoji for display.
 */
#[Api(since: '1.0.0')]
final readonly class Country
{
    /**
     * @param non-empty-string        $code       ISO 3166-1 alpha-2 code (e.g. "BE", "US")
     * @param non-empty-string        $name       English display name
     * @param Continent               $continent  Geographic continent
     * @param list<non-empty-string>  $languages  BCP 47 language codes supported in this country
     * @param non-empty-string        $currency   ISO 4217 currency code (e.g. "EUR", "USD")
     * @param non-empty-string        $flag       Flag emoji (e.g. "\u{1F1E7}\u{1F1EA}" for Belgium)
     */
    public function __construct(
        public string $code,
        public string $name,
        public Continent $continent,
        public array $languages,
        public string $currency,
        public string $flag,
    ) {}

    /**
     * Whether this country supports a given language code.
     */
    #[NoDiscard]
    public function supportsLanguage(string $languageCode): bool
    {
        return in_array($languageCode, $this->languages, true);
    }

    /**
     * The primary (first) language for this country.
     *
     * @return non-empty-string
     */
    #[NoDiscard]
    public function primaryLanguage(): string
    {
        return $this->languages[0];
    }

    /**
     * Serialize to an array suitable for JSON API responses and the frontend selector.
     *
     * @return array{code: string, name: string, continent: string, languages: list<string>, currency: string, flag: string}
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'continent' => $this->continent->value,
            'languages' => $this->languages,
            'currency' => $this->currency,
            'flag' => $this->flag,
        ];
    }
}
