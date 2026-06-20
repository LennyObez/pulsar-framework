<?php

declare(strict_types=1);

namespace Pulsar\I18n\Locale;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\I18n\LocaleNegotiatorInterface;

use function assert;
use function in_array;
use function is_numeric;
use function is_string;
use function preg_match_all;
use function usort;

/**
 * Negotiates the best locale from request data.
 *
 * Priority (highest to lowest):
 * 1. Query parameter `?locale=xx`
 * 2. Request attribute `_locale` (from route parameter)
 * 3. Accept-Language header (RFC 7231, quality-sorted)
 * 4. Config default
 */
#[Internal]
final readonly class LocaleNegotiator implements LocaleNegotiatorInterface
{
    private const int MAX_ACCEPT_LANGUAGE_TOKENS = 20;

    public function negotiate(ServerRequestInterface $request, array $supported, string $default): string
    {
        if ($supported === []) {
            return $default;
        }

        // 1. Query parameter
        /** @var mixed $queryLocale */
        $queryLocale = $request->getQueryParams()['locale'] ?? null;

        if (is_string($queryLocale) && $queryLocale !== '') {
            $match = $this->matchLocale($queryLocale, $supported);

            if ($match !== null) {
                return $match;
            }
        }

        // 2. Request attribute (route parameter)
        /** @var mixed $attrLocale */
        $attrLocale = $request->getAttribute('_locale');

        if (is_string($attrLocale) && $attrLocale !== '') {
            $match = $this->matchLocale($attrLocale, $supported);

            if ($match !== null) {
                return $match;
            }
        }

        // 3. Accept-Language header
        $acceptLanguage = $request->getHeaderLine('Accept-Language');

        if ($acceptLanguage !== '') {
            $match = $this->negotiateFromHeader($acceptLanguage, $supported);

            if ($match !== null) {
                return $match;
            }
        }

        // 4. Config default
        return $default;
    }

    /**
     * Match a candidate locale against the supported list.
     *
     * Tries exact match first, then language-prefix match.
     *
     * @param list<string> $supported
     */
    private function matchLocale(string $candidate, array $supported): ?string
    {
        // Exact match
        if (in_array($candidate, $supported, true)) {
            return $candidate;
        }

        // Language-prefix match: 'fr_CA' matches supported 'fr'
        $language = $this->extractLanguage($candidate);

        foreach ($supported as $locale) {
            if ($locale === $language) {
                return $locale;
            }
        }

        // Reverse: 'fr' matches supported 'fr_CA'
        foreach ($supported as $locale) {
            if ($this->extractLanguage($locale) === $language) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * Parse Accept-Language header and find best match.
     *
     * @param list<string> $supported
     */
    private function negotiateFromHeader(string $header, array $supported): ?string
    {
        $parsed = $this->parseAcceptLanguage($header);

        foreach ($parsed as $candidate) {
            $match = $this->matchLocale($candidate, $supported);

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Parse Accept-Language header into quality-sorted locale list.
     *
     * Caps at MAX_ACCEPT_LANGUAGE_TOKENS entries. Malformed q-values
     * are treated as q=0.
     *
     * @return list<string>
     */
    private function parseAcceptLanguage(string $header): array
    {
        $count = preg_match_all(
            '/([a-zA-Z]{1,8}(?:[-_][a-zA-Z0-9]{1,8})*)(?:\s*;\s*q\s*=\s*([^\s,]*))?/',
            $header,
            $matches,
        );

        if ($count === false || $count === 0) {
            return [];
        }

        /** @var array{list<string>, list<string>, list<string>} $matches */
        $entries = [];
        $limit = min($count, self::MAX_ACCEPT_LANGUAGE_TOKENS);

        for ($i = 0; $i < $limit; $i++) {
            $tag = str_replace('-', '_', $matches[1][$i]);
            $rawQ = $matches[2][$i];
            $quality = 1.0;

            if ($rawQ !== '') {
                if (is_numeric($rawQ)) {
                    $parsedQ = (float) $rawQ;
                    $quality = ($parsedQ >= 0.0 && $parsedQ <= 1.0) ? $parsedQ : 0.0;
                } else {
                    $quality = 0.0;
                }
            }

            $entries[] = ['tag' => $tag, 'quality' => $quality];
        }

        usort($entries, static fn(array $a, array $b): int => $b['quality'] <=> $a['quality']);

        $result = [];

        foreach ($entries as $entry) {
            $result[] = $entry['tag'];
        }

        return $result;
    }

    private function extractLanguage(string $locale): string
    {
        $pos = strpos($locale, '_');

        if ($pos === false) {
            $pos = strpos($locale, '-');
        }

        if ($pos === false) {
            return $locale;
        }

        return substr($locale, 0, $pos);
    }
}
