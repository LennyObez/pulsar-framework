<?php

declare(strict_types=1);

namespace Pulsar\Http;

use NoDiscard;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

use function array_slice;
use function count;
use function explode;
use function preg_match;
use function str_ends_with;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use function usort;

/**
 * RFC 7231 content negotiation via quality-value (q-value) parsing.
 *
 * Parses the Accept, Accept-Language, Accept-Encoding, and Accept-Charset
 * headers to determine the client's preferred content type, language, etc.
 *
 * Quality values (q-factors) range from 0.000 to 1.000 where 1.000 is
 * most preferred and 0 means "not acceptable."
 */
#[Api(since: '1.0.0')]
final class ContentNegotiation
{
    /**
     * Negotiate the best content type from the Accept header.
     *
     * @param ServerRequestInterface $request The incoming request
     * @param list<string> $available Available content types the server can produce
     *
     * @return string|null The negotiated type, or null if no match found
     */
    #[NoDiscard]
    public static function negotiateType(ServerRequestInterface $request, array $available): ?string
    {
        $accept = $request->getHeaderLine('Accept');

        if ($accept === '' || $accept === '*/*') {
            return $available[0] ?? null;
        }

        $preferences = self::parseQualityValues($accept);

        return self::matchPreferences($preferences, $available);
    }

    /**
     * Negotiate the best language from the Accept-Language header.
     *
     * @param ServerRequestInterface $request The incoming request
     * @param list<string> $available Available language tags (e.g. ['en', 'fr', 'de'])
     *
     * @return string|null The negotiated language, or null if no match found
     */
    #[NoDiscard]
    public static function negotiateLanguage(ServerRequestInterface $request, array $available): ?string
    {
        $accept = $request->getHeaderLine('Accept-Language');

        if ($accept === '') {
            return $available[0] ?? null;
        }

        $preferences = self::parseQualityValues($accept);

        return self::matchPreferences($preferences, $available);
    }

    /**
     * Negotiate the best encoding from the Accept-Encoding header.
     *
     * @param ServerRequestInterface $request The incoming request
     * @param list<string> $available Available encodings (e.g. ['gzip', 'br', 'identity'])
     *
     * @return string|null The negotiated encoding, or null if no match found
     */
    #[NoDiscard]
    public static function negotiateEncoding(ServerRequestInterface $request, array $available): ?string
    {
        $accept = $request->getHeaderLine('Accept-Encoding');

        if ($accept === '') {
            return $available[0] ?? null;
        }

        $preferences = self::parseQualityValues($accept);

        return self::matchPreferences($preferences, $available);
    }

    /**
     * Parse an Accept-style header into a sorted list of quality-weighted values.
     *
     * @param string $header The raw header value (e.g. "text/html, application/json;q=0.9")
     *
     * @return list<AcceptValue> Sorted by quality descending, then by specificity
     */
    #[NoDiscard]
    public static function parseQualityValues(string $header): array
    {
        $values = [];
        $parts = explode(',', $header);
        $order = 0;

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $segments = explode(';', $part);
            $type = trim($segments[0]);
            $quality = 1.0;
            /** @var array<string, string> $parameters */
            $parameters = [];

            foreach (array_slice($segments, 1) as $segment) {
                $segment = trim($segment);

                if (preg_match('/^q\s*=\s*(-?[0-9.]+)$/i', $segment, $matches) === 1) {
                    $quality = (float) $matches[1];

                    // Clamp to valid range
                    if ($quality < 0.0) {
                        $quality = 0.0;
                    } elseif ($quality > 1.0) {
                        $quality = 1.0;
                    }
                } else {
                    $eqPos = strpos($segment, '=');
                    if ($eqPos !== false) {
                        $key = trim(substr($segment, 0, $eqPos));
                        $val = trim(substr($segment, $eqPos + 1));
                        $parameters[$key] = $val;
                    }
                }
            }

            $values[] = new AcceptValue(
                value: $type,
                quality: $quality,
                order: $order++,
                parameters: $parameters,
            );
        }

        // Sort by quality descending, then by order ascending (first-wins for ties)
        usort($values, static function (AcceptValue $a, AcceptValue $b): int {
            if ($a->quality !== $b->quality) {
                return $b->quality <=> $a->quality;
            }

            // More specific types win (fewer wildcards)
            $aSpec = self::specificity($a->value);
            $bSpec = self::specificity($b->value);

            if ($aSpec !== $bSpec) {
                return $bSpec <=> $aSpec;
            }

            // Parameter count as tiebreaker (more params = more specific)
            $aParams = count($a->parameters);
            $bParams = count($b->parameters);

            if ($aParams !== $bParams) {
                return $bParams <=> $aParams;
            }

            return $a->order <=> $b->order;
        });

        return $values;
    }

    /**
     * Match client preferences against available server options.
     *
     * @param list<AcceptValue> $preferences Sorted client preferences
     * @param list<string>      $available   Available server options
     *
     * @return string|null Best match or null if none acceptable
     */
    private static function matchPreferences(array $preferences, array $available): ?string
    {
        foreach ($preferences as $preference) {
            if ($preference->quality === 0.0) {
                continue;
            }

            foreach ($available as $option) {
                if (self::matches($preference->value, $option)) {
                    return $option;
                }
            }
        }

        return null;
    }

    /**
     * Check if a preference pattern matches an available value.
     *
     * Supports wildcard matching for media types (star, star-slash-star),
     * subtype wildcards (text/star matches text/html), and exact matching.
     */
    private static function matches(string $pattern, string $value): bool
    {
        $pattern = strtolower($pattern);
        $value = strtolower($value);

        if ($pattern === '*' || $pattern === '*/*') {
            return true;
        }

        if ($pattern === $value) {
            return true;
        }

        // Subtype wildcard: "text/*" matches "text/html"
        if (str_ends_with($pattern, '/*')) {
            $patternType = substr($pattern, 0, -2);
            $valueType = explode('/', $value, 2)[0];

            return $patternType === $valueType;
        }

        return false;
    }

    /**
     * Calculate specificity of a media type.
     *
     * Returns 0 for full wildcards, 1 for subtype wildcards, 2 for specific types.
     */
    private static function specificity(string $type): int
    {
        if ($type === '*' || $type === '*/*') {
            return 0;
        }

        if (str_ends_with($type, '/*')) {
            return 1;
        }

        return 2;
    }
}
