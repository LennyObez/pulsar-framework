<?php

declare(strict_types=1);

namespace Pulsar\I18n\Locale;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\I18nConfig;
use Pulsar\I18n\Exception\I18nException;
use Pulsar\I18n\TranslatorInterface;
use Pulsar\Routing\Router;

use function str_starts_with;
use function strlen;
use function substr;
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
        $slug = $this->registry->slugFor($key, $locale);

        if ($this->router !== null && $this->router->getByName($key) !== null) {
            // Reuse Router::url() for parameter substitution + encoding, then
            // swap the canonical key prefix for the locale's slug.
            return $this->swapPrefix($this->router->url($key, $params), $key, $slug);
        }

        return '/' . $slug;
    }

    /**
     * Replace the leading `/{key}` of a canonical path with `/{slug}`.
     */
    private function swapPrefix(string $canonicalPath, string $key, string $slug): string
    {
        $keyPath = '/' . $key;

        if ($canonicalPath === $keyPath) {
            return '/' . $slug;
        }

        if (str_starts_with($canonicalPath, $keyPath . '/')) {
            return '/' . $slug . substr($canonicalPath, strlen($keyPath));
        }

        return $canonicalPath;
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
