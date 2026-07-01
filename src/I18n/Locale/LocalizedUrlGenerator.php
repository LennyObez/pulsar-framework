<?php

declare(strict_types=1);

namespace Pulsar\I18n\Locale;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\I18nConfig;
use Pulsar\I18n\Exception\I18nException;
use Pulsar\I18n\TranslatorInterface;
use Pulsar\Routing\Router;

use function trim;

/**
 * Generates localized URLs by route key.
 *
 * Where {@see LocaleUrlGenerator} works from an existing path, this generator
 * works from the canonical route *key* and produces the locale-prefixed,
 * slug-translated URL for any supported locale — the primary API for building
 * links in templates and controllers, and the backend for the `route()` helper
 * and the `@route` directive.
 *
 * A global instance is registered at kernel boot (mirroring
 * {@see \Pulsar\I18n\Translator}) so the `route()` helper works without a
 * service locator — see ADR-0021.
 * @api
 */
#[Api(since: '1.0.0')]
final class LocalizedUrlGenerator
{
    private static ?self $globalInstance = null;

    public function __construct(
        private readonly SlugRegistry $registry,
        private readonly I18nConfig $config,
        private readonly UrlPrefixExtractor $extractor,
        private readonly TranslatorInterface $translator,
        private readonly ?Router $router = null,
    ) {}

    /**
     * Build the localized URL for a route key.
     *
     * Translates the key's static prefix to the locale's slug, fills any route
     * parameters (reusing the router's RFC 3986 segment encoding), and applies
     * the locale prefix per the configured URL strategy. Defaults to the
     * current request locale when none is given.
     *
     * @param array<string, string> $params Route parameter values.
     */
    #[NoDiscard]
    public function route(string $key, array $params = [], ?string $locale = null): string
    {
        $key = trim($key, '/');
        $locale = $this->normalizeLocale($locale);

        return $this->extractor->buildPath(
            $this->localizedPath($key, $params, $locale),
            $locale,
            $this->config->defaultLocale,
            $this->config->defaultLocaleInUrl,
        );
    }

    /**
     * Build the localized URL for a key in every supported locale.
     *
     * @param array<string, string> $params
     * @return array<string, string> Locale tag => URL.
     */
    #[NoDiscard]
    public function alternates(string $key, array $params = []): array
    {
        $alternates = [];

        foreach ($this->config->supportedLocales as $locale) {
            $alternates[$locale] = $this->route($key, $params, $locale);
        }

        return $alternates;
    }

    /**
     * Build hreflang links (including `x-default`) for a route key.
     *
     * @param array<string, string> $params
     * @return list<HreflangLink>
     */
    #[NoDiscard]
    public function hreflangLinks(string $key, array $params = []): array
    {
        $alternates = $this->alternates($key, $params);

        $links = [];

        foreach ($alternates as $locale => $href) {
            $links[] = new HreflangLink(locale: $locale, href: $href);
        }

        $defaultHref = $alternates[$this->config->defaultLocale]
            ?? $this->route($key, $params, $this->config->defaultLocale);
        $links[] = new HreflangLink(locale: 'x-default', href: $defaultHref);

        return $links;
    }

    /**
     * Build the localized path (without locale prefix) for a key and params.
     *
     * @param array<string, string> $params
     */
    private function localizedPath(string $key, array $params, string $locale): string
    {
        if ($this->router !== null && $this->router->getByName($key) !== null) {
            // Localize by the resolved PATH, not the route name. A detail route
            // is named e.g. `development/projects.detail` but its path is
            // `/development/projects/{slug}` — the `.detail` suffix never appears
            // in the path. Matching the route name against the path therefore
            // fails to find the slug prefix. Instead, Router::url() fills params
            // + encodes, and the slug prefix is matched against the path (longest
            // registered key-prefix), preserving the parameter tail.
            return $this->localizeByPath($this->router->url($key, $params), $locale);
        }

        return '/' . $this->registry->slugFor($key, $locale);
    }

    /**
     * Translate the registered key-prefix of a canonical path to the locale's
     * slug, preserving the remainder (the parameter segments).
     *
     * Paths whose prefix is not a registered slug key are returned unchanged
     * (so e.g. `blog.post` without a localized parent slug stays `/blog/{slug}`).
     */
    private function localizeByPath(string $canonicalPath, string $locale): string
    {
        $match = $this->registry->matchKey($canonicalPath);

        if ($match === null) {
            return $canonicalPath;
        }

        $slug = $this->registry->slugFor($match->key, $locale);

        return $match->remainder !== ''
            ? '/' . $slug . '/' . $match->remainder
            : '/' . $slug;
    }

    private function normalizeLocale(?string $locale): string
    {
        if ($locale !== null && $locale !== '') {
            return $locale;
        }

        return $this->translator->locale !== '' ? $this->translator->locale : $this->config->defaultLocale;
    }

    /**
     * Register the global instance (called by I18nWiring at boot).
     */
    public static function setGlobalInstance(self $instance): void
    {
        self::$globalInstance = $instance;
    }

    /**
     * Get the global instance for the `route()` helper.
     *
     * @throws I18nException If localized routing is not booted.
     */
    #[NoDiscard]
    public static function getGlobalInstance(): self
    {
        return self::$globalInstance ?? throw I18nException::notBooted();
    }

    /**
     * Reset the global instance (for testing).
     */
    public static function resetGlobalInstance(): void
    {
        self::$globalInstance = null;
    }
}
