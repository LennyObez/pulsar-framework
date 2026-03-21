<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\I18n\Locale\LocaleUrlStrategy;

use function is_array;
use function is_int;
use function is_string;

/**
 * Typed configuration DTO for `config/i18n.php`.
 *
 * Environment variables `APP_LOCALE` and `I18N_REGULATED` override file values.
 */
#[Api(since: '1.0.0')]
final readonly class I18nConfig
{
    /**
     * @param list<string> $supportedLocales
     * @param list<string> $fallbackLocales
     */
    public function __construct(
        public string $defaultLocale,
        public array $supportedLocales,
        public array $fallbackLocales,
        public ?string $catalogPath,
        public bool $regulated,
        public int $maxSupportedLocales,
        public bool $strictMode,
        public LocaleUrlStrategy $urlStrategy = LocaleUrlStrategy::None,
        public bool $defaultLocaleInUrl = false,
        public bool $canonicalRedirect = true,
    ) {}

    /**
     * Build an I18nConfig from a raw config array and environment.
     *
     * @param array<string, mixed> $data Raw array from config/i18n.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        // Resolve default locale: env var overrides file value
        $rawDefaultLocale = $data['default_locale'] ?? 'en';
        $defaultLocale = $environment->get('APP_LOCALE') ?? (is_string($rawDefaultLocale) ? $rawDefaultLocale : 'en');

        // Resolve supported locales
        $rawSupported = $data['supported_locales'] ?? ['en'];
        $supportedLocales = is_array($rawSupported) ? self::filterStringList($rawSupported) : ['en'];

        if ($supportedLocales === []) {
            $supportedLocales = ['en'];
        }

        // Resolve fallback locales
        $rawFallback = $data['fallback_locales'] ?? ['en'];
        $fallbackLocales = is_array($rawFallback) ? self::filterStringList($rawFallback) : ['en'];

        if ($fallbackLocales === []) {
            $fallbackLocales = ['en'];
        }

        // Resolve catalog path
        $rawCatalogPath = $data['catalog_path'] ?? null;
        $catalogPath = is_string($rawCatalogPath) ? $rawCatalogPath : null;

        // Resolve regulated: env var overrides file value
        $regulatedEnv = $environment->get('I18N_REGULATED');

        if ($regulatedEnv !== null) {
            $regulated = self::parseBool($regulatedEnv);
        } else {
            $regulated = (bool) ($data['regulated'] ?? false);
        }

        // Resolve max supported locales
        $rawMax = $data['max_supported_locales'] ?? 50;
        $maxSupportedLocales = is_int($rawMax) ? $rawMax : 50;

        // Resolve strict mode
        $strictMode = (bool) ($data['strict_mode'] ?? false);

        // Resolve URL strategy
        $rawUrlStrategy = $data['url_strategy'] ?? 'none';
        $urlStrategy = is_string($rawUrlStrategy)
            ? (LocaleUrlStrategy::tryFrom($rawUrlStrategy) ?? LocaleUrlStrategy::None)
            : LocaleUrlStrategy::None;

        // Resolve default locale in URL
        $defaultLocaleInUrl = (bool) ($data['default_locale_in_url'] ?? false);

        // Resolve canonical redirect
        $canonicalRedirect = (bool) ($data['canonical_redirect'] ?? true);

        return new self(
            defaultLocale: $defaultLocale,
            supportedLocales: $supportedLocales,
            fallbackLocales: $fallbackLocales,
            catalogPath: $catalogPath,
            regulated: $regulated,
            maxSupportedLocales: $maxSupportedLocales,
            strictMode: $strictMode,
            urlStrategy: $urlStrategy,
            defaultLocaleInUrl: $defaultLocaleInUrl,
            canonicalRedirect: $canonicalRedirect,
        );
    }

    /**
     * Filter an array down to string values only, re-indexed as a list.
     *
     * @param array<array-key, mixed> $items
     * @return list<string>
     */
    private static function filterStringList(array $items): array
    {
        $strings = [];

        foreach ($items as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    private static function parseBool(string $value): bool
    {
        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            default => false,
        };
    }
}
