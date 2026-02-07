<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\I18n;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsConfig;

use function explode;
use function in_array;
use function ltrim;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function usort;

/**
 * Resolves the active locale from an HTTP request.
 *
 * Resolution order:
 *  1. URL prefix (e.g., /fr/about -> 'fr')
 *  2. Accept-Language header negotiation
 *  3. Default locale from CmsConfig
 */
#[Internal(reason: 'CMS i18n implementation detail')]
final readonly class LocaleResolver
{
    /**
     * Resolve the active locale from the request.
     *
     * Returns the BCP 47 locale code determined by URL prefix,
     * Accept-Language header, or the configured default.
     */
    public function resolve(ServerRequestInterface $request, CmsConfig $config): string
    {
        $path = ltrim($request->getUri()->getPath(), '/');

        // 1. Check URL prefix for locale
        if ($path !== '' && preg_match('#^([a-z]{2}(?:-[A-Z]{2})?)(?:/|$)#', $path, $matches) === 1) {
            $candidate = $matches[1];

            if (in_array($candidate, $config->supportedLocales, true)) {
                // For default locale without URL prefix mode, treat as content path
                if ($candidate === $config->defaultLocale && !$config->defaultLocaleInUrl) {
                    return $config->defaultLocale;
                }

                return $candidate;
            }
        }

        // 2. Accept-Language header negotiation
        $acceptLanguage = $request->getHeaderLine('Accept-Language');

        if ($acceptLanguage !== '') {
            $negotiated = $this->negotiateAcceptLanguage($acceptLanguage, $config->supportedLocales);

            if ($negotiated !== null) {
                return $negotiated;
            }
        }

        // 3. Default locale
        return $config->defaultLocale;
    }

    /**
     * Strip the locale prefix from a request path.
     *
     * Returns the content path without the locale segment.
     */
    public function stripLocalePrefix(string $path, string $locale, CmsConfig $config): string
    {
        $path = ltrim($path, '/');

        if ($locale === $config->defaultLocale && !$config->defaultLocaleInUrl) {
            return $path;
        }

        $prefix = $locale . '/';

        if (str_starts_with($path, $prefix)) {
            return substr($path, strlen($prefix));
        }

        if ($path === $locale) {
            return '';
        }

        return $path;
    }

    /**
     * Build a locale-prefixed path.
     *
     * When the locale is the default and defaultLocaleInUrl is false,
     * returns the path without a locale prefix. Otherwise prepends
     * /{locale}/ to the path.
     */
    public function buildPath(string $path, string $locale, CmsConfig $config): string
    {
        $path = trim($path, '/');

        if ($locale === $config->defaultLocale && !$config->defaultLocaleInUrl) {
            return '/' . $path;
        }

        if ($path === '') {
            return '/' . $locale;
        }

        return '/' . $locale . '/' . $path;
    }

    /**
     * Negotiate the best locale from an Accept-Language header value.
     *
     * Parses quality values and returns the highest-quality match
     * from the supported locales list, or null if no match.
     *
     * @param list<string> $supportedLocales
     */
    private function negotiateAcceptLanguage(string $header, array $supportedLocales): ?string
    {
        $candidates = [];

        foreach (explode(',', $header) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $quality = 1.0;

            if (str_contains($part, ';')) {
                $segments = explode(';', $part, 2);
                $part = trim($segments[0]);

                if (preg_match('/q\s*=\s*([0-9.]+)/', $segments[1], $qMatch) === 1) {
                    $quality = (float) $qMatch[1];
                }
            }

            $candidates[] = ['locale' => strtolower($part), 'quality' => $quality];
        }

        // Sort by quality descending
        usort($candidates, static fn(array $a, array $b): int => $b['quality'] <=> $a['quality']);

        foreach ($candidates as $candidate) {
            $locale = $candidate['locale'];

            // Exact match
            if (in_array($locale, $supportedLocales, true)) {
                return $locale;
            }

            // Language-only match (e.g., "en-us" matches "en")
            $language = explode('-', $locale, 2)[0];

            foreach ($supportedLocales as $supported) {
                if (strtolower($supported) === $language || str_starts_with(strtolower($supported), $language . '-')) {
                    return $supported;
                }
            }
        }

        return null;
    }
}
