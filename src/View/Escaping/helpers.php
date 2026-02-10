<?php

declare(strict_types=1);

use Pulsar\View\Escaping\AttributeEscaper;
use Pulsar\View\Escaping\CssEscaper;
use Pulsar\View\Escaping\JsEscaper;
use Pulsar\View\Escaping\UrlEscaper;

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
