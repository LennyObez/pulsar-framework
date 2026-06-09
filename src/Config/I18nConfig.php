<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\I18n\Locale\LocaleUrlStrategy;
use Pulsar\Support\Coerce;

/**
 * Typed configuration DTO for `config/i18n.php`.
 *
 * Environment variables `APP_LOCALE` and `I18N_REGULATED` override file values.
 * @api
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
     * @param array{
     *     default_locale?: string,
     *     supported_locales?: list<string>,
     *     fallback_locales?: list<string>,
     *     catalog_path?: string|null,
     *     regulated?: bool|int|string,
     *     max_supported_locales?: int,
     *     strict_mode?: bool|int|string,
     *     url_strategy?: string,
     *     default_locale_in_url?: bool|int|string,
     *     canonical_redirect?: bool|int|string,
     * } $data Raw array from config/i18n.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $localeEnv = $environment->get('APP_LOCALE');
        $defaultLocale = $localeEnv ?? Coerce::string($data['default_locale'] ?? null, 'en');

        $supportedLocales = Coerce::listOfString($data['supported_locales'] ?? null, ['en']);
        if ($supportedLocales === []) {
            $supportedLocales = ['en'];
        }

        $fallbackLocales = Coerce::listOfString($data['fallback_locales'] ?? null, ['en']);
        if ($fallbackLocales === []) {
            $fallbackLocales = ['en'];
        }

        $regulatedEnv = $environment->get('I18N_REGULATED');
        $regulated = $regulatedEnv !== null
            ? self::parseBool($regulatedEnv)
            : (bool) ($data['regulated'] ?? false);

        $urlStrategy = LocaleUrlStrategy::tryFrom(Coerce::string($data['url_strategy'] ?? null, 'none')) ?? LocaleUrlStrategy::None;

        return new self(
            defaultLocale: $defaultLocale,
            supportedLocales: $supportedLocales,
            fallbackLocales: $fallbackLocales,
            catalogPath: Coerce::nullableString($data['catalog_path'] ?? null),
            regulated: $regulated,
            maxSupportedLocales: Coerce::int($data['max_supported_locales'] ?? null, 50),
            strictMode: (bool) ($data['strict_mode'] ?? false),
            urlStrategy: $urlStrategy,
            defaultLocaleInUrl: (bool) ($data['default_locale_in_url'] ?? false),
            canonicalRedirect: (bool) ($data['canonical_redirect'] ?? true),
        );
    }

    private static function parseBool(string $value): bool
    {
        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            default => false,
        };
    }
}
