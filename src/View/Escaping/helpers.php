<?php

declare(strict_types=1);

use Pulsar\View\Escaping\AttributeEscaper;
use Pulsar\View\Escaping\CssEscaper;
use Pulsar\View\Escaping\JsEscaper;
use Pulsar\View\Escaping\UrlEscaper;

/*
 * Per-helper static memoization rationale (M-4 audit response):
 *
 * Each escaper is `final readonly` with no instance state. The
 * `static $escaper` cache inside each helper below is therefore not
 * "global state" in the harmful sense:
 *
 *   1. PHP request lifetimes are isolated; the static resets between
 *      FPM/CGI requests and is collected by Pulsar's Resettable hook
 *      in persistent runtimes.
 *   2. Readonly fields cannot mutate, so two callers cannot observe
 *      a different escaper depending on call order.
 *   3. The cache exists only to avoid repeated allocation of value
 *      objects in hot view rendering loops.
 *
 * Removing the cache would re-instantiate the escapers on every
 * `{{ url($x) }}` render, which is wasted work for zero security
 * or testability benefit.
 */

if (!function_exists('url')) {
    /**
     * Escape a value for URL context (href/src attributes).
     *
     * Blocks dangerous URI schemes (javascript:, data:, vbscript:).
     */
    function url(string $value): string
    {
        /** @var UrlEscaper|null $escaper */
        static $escaper;
        $escaper ??= new UrlEscaper();

        return $escaper->escape($value);
    }
}

if (!function_exists('attr')) {
    /**
     * Escape a value for HTML attribute context.
     *
     * Encodes all non-alphanumeric characters as numeric HTML entities.
     */
    function attr(string $value): string
    {
        /** @var AttributeEscaper|null $escaper */
        static $escaper;
        $escaper ??= new AttributeEscaper();

        return $escaper->escape($value);
    }
}

if (!function_exists('js')) {
    /**
     * Escape a value for JavaScript inline context.
     *
     * JSON-encodes with HTML-safe flags for use in <script> blocks.
     */
    function js(string $value): string
    {
        /** @var JsEscaper|null $escaper */
        static $escaper;
        $escaper ??= new JsEscaper();

        return $escaper->escape($value);
    }
}

if (!function_exists('css')) {
    /**
     * Escape a value for CSS inline context.
     *
     * Encodes non-alphanumeric characters as CSS hex escapes.
     */
    function css(string $value): string
    {
        /** @var CssEscaper|null $escaper */
        static $escaper;
        $escaper ??= new CssEscaper();

        return $escaper->escape($value);
    }
}
