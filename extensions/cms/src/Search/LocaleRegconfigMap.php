<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Search;

use function substr;

/**
 * Maps BCP 47 locale codes to PostgreSQL text search regconfig names.
 *
 * @psalm-api Static utility invoked by name from PostgresSearchService and
 *            SearchVectorComputer; never instantiated.
 */
final class LocaleRegconfigMap
{
    /** @var array<string, string> */
    private const array MAP = [
        'en' => 'english',
        'fr' => 'french',
        'de' => 'german',
        'nl' => 'dutch',
        'es' => 'spanish',
        'it' => 'italian',
        'pt' => 'portuguese',
        'ru' => 'russian',
        'sv' => 'swedish',
        'da' => 'danish',
        'fi' => 'finnish',
        'hu' => 'hungarian',
        'no' => 'norwegian',
        'ro' => 'romanian',
        'tr' => 'turkish',
        'ar' => 'arabic',
    ];

    /**
     * Resolve a locale to a PostgreSQL regconfig name.
     *
     * Falls back to 'simple' for unsupported languages.
     */
    public static function resolve(string $locale): string
    {
        $lang = substr($locale, 0, 2);

        return self::MAP[$lang] ?? 'simple';
    }
}
