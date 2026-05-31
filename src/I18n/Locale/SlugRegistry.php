<?php

declare(strict_types=1);

namespace Pulsar\I18n\Locale;

use NoDiscard;
use Pulsar\Api\Api;

use function array_keys;
use function array_slice;
use function count;
use function explode;
use function implode;
use function is_string;
use function max;
use function min;
use function trim;

/**
 * Compiled registry mapping route keys to per-locale URL slugs.
 *
 * A route *key* is the canonical (English) static path prefix a route is
 * registered under, e.g. `development/projects`. Each key may declare a
 * translated slug per locale (`developpement/projets` for `fr`). Locales
 * without a declared slug fall back to the key itself.
 *
 * The registry precomputes the forward (locale + slug → key) and reverse
 * (key + locale → slug) lookup tables once, at boot, so request-time
 * resolution is a handful of array probes with no per-request parsing or
 * database access. It is immutable and safe to serialize into the route
 * cache.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SlugRegistry
{
    /**
     * @param array<string, true>                  $keys        Set of canonical route keys.
     * @param array<string, array<string, string>> $formToKey   locale => inbound form => key
     *        (canonical slugs plus key aliases).
     * @param array<string, array<string, string>> $keyToSlug   key => locale => canonical slug.
     * @param list<string>                         $supportedLocales
     * @param array<string, array<string, string>> $localizedSlugs Raw declared map (for linting).
     * @param int                                  $maxSegments Largest segment count across keys and slugs.
     */
    private function __construct(
        private array $keys,
        private array $formToKey,
        private array $keyToSlug,
        private array $supportedLocales,
        private array $localizedSlugs,
        private int $maxSegments,
    ) {}

    /**
     * Compile a registry from the raw `localized_slugs` config map.
     *
     * @param array<string, array<string, string>> $localizedSlugs key => (locale => slug)
     * @param list<string>                          $supportedLocales
     */
    #[NoDiscard]
    public static function fromConfig(array $localizedSlugs, array $supportedLocales): self
    {
        $keys = [];
        $keyToSlug = [];
        $normalized = [];
        $maxSegments = 1;

        foreach ($localizedSlugs as $rawKey => $slugMap) {
            $key = trim($rawKey, '/');

            // Empty keys are meaningless as route keys; the config layer
            // (I18nConfig::parseLocalizedSlugs) has already coerced values to
            // the declared shape, so no further structural defence is needed.
            if ($key === '') {
                continue;
            }

            $keys[$key] = true;
            $maxSegments = max($maxSegments, self::segmentCount($key));

            foreach ($supportedLocales as $locale) {
                $slug = $slugMap[$locale] ?? null;
                $canonical = is_string($slug) && trim($slug, '/') !== '' ? trim($slug, '/') : $key;
                $keyToSlug[$key][$locale] = $canonical;
                $maxSegments = max($maxSegments, self::segmentCount($canonical));
            }

            // Preserve a copy of the declared (non-fallback) slugs for the linter.
            foreach ($slugMap as $locale => $slug) {
                if (trim($slug, '/') !== '') {
                    $normalized[$key][$locale] = trim($slug, '/');
                }
            }
        }

        $formToKey = self::buildFormIndex($keys, $keyToSlug, $supportedLocales);

        return new self($keys, $formToKey, $keyToSlug, $supportedLocales, $normalized, $maxSegments);
    }

    /**
     * Build the inbound `locale => form => key` index.
     *
     * Canonical slugs are indexed first so that, when a route key collides with
     * another key's canonical slug, the canonical form wins. Each key is then
     * registered as a cross-locale alias for itself unless that form is already
     * taken by a canonical slug.
     *
     * @param array<string, true>                  $keys
     * @param array<string, array<string, string>> $keyToSlug
     * @param list<string>                          $supportedLocales
     * @return array<string, array<string, string>>
     */
    private static function buildFormIndex(array $keys, array $keyToSlug, array $supportedLocales): array
    {
        $formToKey = [];

        foreach ($supportedLocales as $locale) {
            foreach (array_keys($keys) as $key) {
                $canonical = $keyToSlug[$key][$locale] ?? $key;
                $formToKey[$locale][$canonical] = $key;
            }

            foreach (array_keys($keys) as $key) {
                if (!isset($formToKey[$locale][$key])) {
                    $formToKey[$locale][$key] = $key;
                }
            }
        }

        return $formToKey;
    }

    /**
     * Whether no slugs are registered (feature effectively disabled).
     */
    #[NoDiscard]
    public function isEmpty(): bool
    {
        return $this->keys === [];
    }

    /**
     * Whether the given canonical key is registered.
     */
    #[NoDiscard]
    public function hasKey(string $key): bool
    {
        return isset($this->keys[trim($key, '/')]);
    }

    /**
     * Canonical localized slug for a key, falling back to the key itself.
     */
    #[NoDiscard]
    public function slugFor(string $key, string $locale): string
    {
        $key = trim($key, '/');

        return $this->keyToSlug[$key][$locale] ?? $key;
    }

    /**
     * Resolve an inbound localized path to its route key for the active locale.
     *
     * Performs a longest-prefix match: the most specific (deepest) registered
     * slug that prefixes the path wins, so `developpement/projets` is preferred
     * over `developpement` when both are registered.
     */
    #[NoDiscard]
    public function matchLocalized(string $locale, string $path): ?SlugMatch
    {
        $forms = $this->formToKey[$locale] ?? [];

        if ($forms === []) {
            return null;
        }

        $segments = self::segments($path);
        $depth = min($this->maxSegments, count($segments));

        for ($n = $depth; $n >= 1; $n--) {
            $form = implode('/', array_slice($segments, 0, $n));

            if (isset($forms[$form])) {
                $key = $forms[$form];
                $canonical = $this->keyToSlug[$key][$locale] ?? $key;

                return new SlugMatch(
                    key: $key,
                    remainder: implode('/', array_slice($segments, $n)),
                    isCanonical: $form === $canonical,
                );
            }
        }

        return null;
    }

    /**
     * Resolve a canonical key-path (post-rewrite) back to its route key.
     *
     * Used by URL generation to translate a path the application sees into
     * per-locale slugs. Longest-prefix match against the registered keys.
     */
    #[NoDiscard]
    public function matchKey(string $path): ?SlugMatch
    {
        if ($this->keys === []) {
            return null;
        }

        $segments = self::segments($path);
        $depth = min($this->maxSegments, count($segments));

        for ($n = $depth; $n >= 1; $n--) {
            $form = implode('/', array_slice($segments, 0, $n));

            if (isset($this->keys[$form])) {
                return new SlugMatch(
                    key: $form,
                    remainder: implode('/', array_slice($segments, $n)),
                    isCanonical: true,
                );
            }
        }

        return null;
    }

    /**
     * All registered route keys.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function keys(): array
    {
        return array_keys($this->keys);
    }

    /**
     * Supported locales the registry was compiled for.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function supportedLocales(): array
    {
        return $this->supportedLocales;
    }

    /**
     * The declared (non-fallback) slug map, normalized — used by the linter.
     *
     * @return array<string, array<string, string>>
     */
    #[NoDiscard]
    public function declaredSlugs(): array
    {
        return $this->localizedSlugs;
    }

    /**
     * Split a path into non-empty segments.
     *
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        $trimmed = trim($path, '/');

        if ($trimmed === '') {
            return [];
        }

        return explode('/', $trimmed);
    }

    private static function segmentCount(string $path): int
    {
        return count(self::segments($path));
    }
}
