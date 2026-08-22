<?php

declare(strict_types=1);

namespace Pulsar\Security\Escaper;

use Pulsar\Api\Api;

use function htmlspecialchars;
use function json_encode;
use function preg_replace;
use function rawurlencode;
use function strip_tags;
use function strlen;
use function substr;

/**
 * Context-aware escaping engine.
 *
 * Applies the correct escaping strategy depending on the output context
 * (HTML body, HTML attribute, JavaScript, CSS value, URL component).
 *
 * This prevents XSS and injection attacks by ensuring that user-controlled
 * data is escaped appropriately for where it appears in the rendered output.
 * @api
 */
#[Api(since: '1.0.0')]
final class ContextEscaper
{
    /**
     * Escape for HTML body context (between tags).
     *
     * Converts &, <, >, ", ' to their HTML entity equivalents.
     */
    public static function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Escape for use inside an HTML attribute value.
     *
     * In addition to standard HTML escaping, encodes any characters
     * that could break out of attribute context.
     */
    public static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Escape for embedding inside a JavaScript string literal.
     *
     * Uses JSON encoding for safe JS string representation, then strips
     * the surrounding quotes. Handles </script> injection.
     */
    public static function js(string $value): string
    {
        $encoded = json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR);

        // Remove surrounding double quotes added by json_encode
        if ($encoded !== false && strlen($encoded) >= 2) {
            return substr($encoded, 1, -1);
        }

        return '';
    }

    /**
     * Escape for use inside a CSS value (e.g., style attribute).
     *
     * Strips dangerous CSS constructs like url(), expression(), etc.
     * Only allows alphanumerics, spaces, hyphens, underscores, dots,
     * hashes (for colors), and percentages.
     */
    public static function css(string $value): string
    {
        // Remove any CSS function calls that could execute code. The strip
        // runs until the string stabilises so that overlapping or nested
        // function names (e.g. "expexpression(ression(") cannot reconstruct
        // a dangerous "expression(" prefix after a single pass.
        $stripped = $value;

        do {
            $previous = $stripped;
            $stripped = preg_replace(
                '/(?:expression|url|import|calc|attr|var|env)\s*\(/i',
                '',
                $stripped,
            );

            if ($stripped === null) {
                return '';
            }
        } while ($stripped !== $previous);

        // Allow safe CSS characters only
        $safe = preg_replace('/[^a-zA-Z0-9\s\-_.,#%()\/]/', '', $stripped);

        return $safe ?? '';
    }

    /**
     * Escape for use as a URL component (path segment or query parameter).
     *
     * Uses RFC 3986 percent-encoding.
     */
    public static function url(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * Escape a full URL, validating the scheme.
     *
     * Only allows http://, https://, and mailto: schemes.
     * Returns empty string for javascript:, data:, and other schemes.
     */
    public static function urlFull(string $url): string
    {
        $url = trim($url);

        // Block dangerous schemes
        $lowerUrl = strtolower($url);
        if (str_starts_with($lowerUrl, 'javascript:')
            || str_starts_with($lowerUrl, 'data:')
            || str_starts_with($lowerUrl, 'vbscript:')) {
            return '';
        }

        // Allow relative URLs, http(s), and mailto
        if ($url === '' || $url[0] === '/' || $url[0] === '#' || $url[0] === '?') {
            return self::html($url);
        }

        if (str_starts_with($lowerUrl, 'http://') || str_starts_with($lowerUrl, 'https://') || str_starts_with($lowerUrl, 'mailto:')) {
            return self::html($url);
        }

        // Unknown scheme: block
        return '';
    }

    /**
     * Strip all HTML tags from input.
     */
    public static function stripTags(string $value): string
    {
        return strip_tags($value);
    }

    /**
     * Auto-detect context from the position hint and escape accordingly.
     */
    public static function escape(string $value, EscapeContext $context): string
    {
        return match ($context) {
            EscapeContext::Html => self::html($value),
            EscapeContext::Attribute => self::attr($value),
            EscapeContext::JavaScript => self::js($value),
            EscapeContext::Css => self::css($value),
            EscapeContext::Url => self::url($value),
            EscapeContext::UrlFull => self::urlFull($value),
        };
    }
}
