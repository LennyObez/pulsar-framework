<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\I18n\Locale\LocaleUrlStrategy;

use function is_string;

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
        $defaultLocale = $environment->get('APP_LOCALE') ?? $data['default_locale'] ?? 'en';

        $supportedLocales = self::filterStringList($data['supported_locales'] ?? ['en']);
        if ($supportedLocales === []) {
            $supportedLocales = ['en'];
        }

        $fallbackLocales = self::filterStringList($data['fallback_locales'] ?? ['en']);
        if ($fallbackLocales === []) {
            $fallbackLocales = ['en'];
        }

        $regulatedEnv = $environment->get('I18N_REGULATED');
        $regulated = $regulatedEnv !== null
            ? self::parseBool($regulatedEnv)
            : (bool) ($data['regulated'] ?? false);

        $urlStrategy = LocaleUrlStrategy::tryFrom($data['url_strategy'] ?? 'none') ?? LocaleUrlStrategy::None;

        return new self(
            defaultLocale: $defaultLocale,
            supportedLocales: $supportedLocales,
            fallbackLocales: $fallbackLocales,
            catalogPath: $data['catalog_path'] ?? null,
            regulated: $regulated,
            maxSupportedLocales: $data['max_supported_locales'] ?? 50,
            strictMode: (bool) ($data['strict_mode'] ?? false),
            urlStrategy: $urlStrategy,
            defaultLocaleInUrl: (bool) ($data['default_locale_in_url'] ?? false),
            canonicalRedirect: (bool) ($data['canonical_redirect'] ?? true),
        );
    }

    /**
     * Filter an array down to string values only, re-indexed as a list.
     *
     * @param list<string> $items
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
