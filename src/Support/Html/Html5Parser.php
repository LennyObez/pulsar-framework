<?php

declare(strict_types=1);

namespace Pulsar\Support\Html;

use Dom\HTMLDocument;
use Dom\Node;
use Pulsar\Api\Api;

use function array_filter;
use function array_map;
use function implode;
use function in_array;
use function strtolower;
use function trim;

use const LIBXML_NOERROR;

/**
 * HTML5-spec-compliant parser using PHP 8.4's native \Dom\HTMLDocument.
 *
 * Provides a clean API over the spec-compliant HTML5 parser with utilities
 * for text extraction, element querying, sanitization, and structural
 * validation. No third-party dependencies: uses the engine built into PHP.
 * @api
 */
#[Api(since: '1.0.0')]
final class Html5Parser
{
    /**
     * Parse an HTML string into a DOM document.
     *
     * Uses the spec-compliant HTML5 parser (not the legacy libxml2 parser).
     * Handles fragments and full documents equally well.
     */
    public static function parse(string $html): HTMLDocument
    {
        return HTMLDocument::createFromString($html, LIBXML_NOERROR);
    }

    /**
     * Parse an HTML file into a DOM document.
     */
    public static function parseFile(string $path): HTMLDocument
    {
        return HTMLDocument::createFromFile($path, LIBXML_NOERROR);
    }

    /**
     * Extract all visible text content from an HTML string.
     *
     * Strips tags and returns only text nodes, excluding content inside
     * script, style, and other non-visual elements. Useful for DLP scanning,
     * search indexing, and content analysis.
     *
     * @param list<string> $excludeTags Tag names to exclude (default: script, style, noscript, template)
     */
    public static function extractText(string $html, array $excludeTags = ['script', 'style', 'noscript', 'template']): string
    {
        $doc = self::parse($html);
        /** @var mixed $body */
        $body = $doc->body;

        if (!$body instanceof Node) {
            return '';
        }

        $parts = [];
        self::collectTextNodes($body, $excludeTags, $parts);

        return trim(implode(' ', array_filter($parts, static fn(string $s): bool => $s !== '')));
    }

    /**
     * Query elements using CSS selectors.
     *
     * @return list<\Dom\Element>
     */
    public static function querySelectorAll(string $html, string $selector): array
    {
        $doc = self::parse($html);
        $nodeList = $doc->querySelectorAll($selector);

        $elements = [];

        /** @var mixed $node */
        foreach ($nodeList as $node) {
            if ($node instanceof \Dom\Element) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    /**
     * Query a single element using a CSS selector.
     */
    public static function querySelector(string $html, string $selector): ?\Dom\Element
    {
        $doc = self::parse($html);
        $node = $doc->querySelector($selector);

        return $node instanceof \Dom\Element ? $node : null;
    }

    /**
     * Extract attribute values from all matching elements.
     *
     * @return list<string>
     */
    public static function extractAttributes(string $html, string $selector, string $attribute): array
    {
        $elements = self::querySelectorAll($html, $selector);

        return array_values(array_filter(
            array_map(
                static fn(\Dom\Element $el): string => $el->getAttribute($attribute) ?? '',
                $elements,
            ),
            static fn(string $v): bool => $v !== '',
        ));
    }

    /**
     * Sanitize HTML by removing dangerous elements and attributes.
     *
     * Keeps only elements in the allow-list and strips event handlers,
     * javascript: URIs, and other XSS vectors.
     *
     * @param list<string> $allowedTags Tags to keep (default: safe inline/block elements)
     * @param list<string> $allowedAttributes Attributes to keep (default: safe attributes)
     */
    public static function sanitize(
        string $html,
        array $allowedTags = [
            'p', 'br', 'b', 'i', 'em', 'strong', 'a', 'ul', 'ol', 'li',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'code', 'pre',
            'table', 'thead', 'tbody', 'tr', 'th', 'td', 'img', 'figure',
            'figcaption', 'span', 'div', 'section', 'article', 'aside',
            'details', 'summary', 'mark', 'abbr', 'cite', 'time', 'sub', 'sup',
        ],
        array $allowedAttributes = [
            'href', 'src', 'alt', 'title', 'class', 'id', 'width', 'height',
            'target', 'rel', 'colspan', 'rowspan', 'datetime', 'lang', 'dir',
            'role', 'aria-label', 'aria-labelledby', 'aria-describedby',
            'aria-hidden', 'aria-live', 'aria-controls', 'aria-expanded',
            'aria-selected', 'tabindex', 'data-*',
        ],
    ): string {
        $doc = self::parse($html);
        /** @var mixed $body */
        $body = $doc->body;

        if (!$body instanceof Node) {
            return '';
        }

        self::sanitizeNode($body, $allowedTags, $allowedAttributes);

        return $doc->saveHtml($body);
    }

    /**
     * Validate HTML structure against expected patterns.
     *
     * Returns a list of structural issues found in the HTML.
     *
     * @return list<string> Validation messages (empty = valid)
     */
    public static function validate(string $html): array
    {
        $issues = [];
        $doc = self::parse($html);

        if ($doc->body === null) {
            $issues[] = 'Document has no body element';

            return $issues;
        }

        // Check for images without alt text
        $images = $doc->querySelectorAll('img');

        /** @var mixed $img */
        foreach ($images as $img) {
            if ($img instanceof \Dom\Element && !$img->hasAttribute('alt')) {
                $src = $img->getAttribute('src') ?? '(unknown)';
                $issues[] = "Image missing alt attribute: $src";
            }
        }

        // Check for links without href
        $links = $doc->querySelectorAll('a');

        /** @var mixed $link */
        foreach ($links as $link) {
            if ($link instanceof \Dom\Element && !$link->hasAttribute('href')) {
                $issues[] = 'Anchor element missing href attribute';
            }
        }

        // Check heading hierarchy
        $lastLevel = 0;

        for ($level = 1; $level <= 6; $level++) {
            $headings = $doc->querySelectorAll("h$level");

            if ($headings->length > 0) {
                if ($lastLevel > 0 && $level > $lastLevel + 1) {
                    $issues[] = "Heading level skipped: h$lastLevel to h$level";
                }

                $lastLevel = $level;
            }
        }

        // Check for empty interactive elements
        $buttons = $doc->querySelectorAll('button');

        /** @var mixed $btn */
        foreach ($buttons as $btn) {
            if ($btn instanceof \Dom\Element) {
                /** @var mixed $rawText */
                $rawText = $btn->textContent ?? '';
                if (trim(is_string($rawText) ? $rawText : '') === ''
                    && !$btn->hasAttribute('aria-label')
                    && !$btn->hasAttribute('aria-labelledby')) {
                    $issues[] = 'Button has no text content or aria-label';
                }
            }
        }

        return $issues;
    }

    /**
     * Recursively collect text from nodes, excluding specified tag names.
     *
     * @param list<string> $excludeTags
     * @param list<string> $parts
     */
    private static function collectTextNodes(Node $node, array $excludeTags, array &$parts): void
    {
        /** @var mixed $child */
        foreach ($node->childNodes as $child) {
            if ($child instanceof \Dom\Text) {
                /** @var mixed $rawText */
                $rawText = $child->textContent ?? '';
                $text = trim(is_string($rawText) ? $rawText : '');

                if ($text !== '') {
                    $parts[] = $text;
                }
            } elseif ($child instanceof \Dom\Element) {
                /** @var mixed $localName */
                $localName = $child->localName;
                if (!in_array(strtolower(is_string($localName) ? $localName : ''), $excludeTags, true)) {
                    self::collectTextNodes($child, $excludeTags, $parts);
                }
            }
        }
    }

    /**
     * Recursively sanitize a DOM node tree.
     *
     * @param list<string> $allowedTags
     * @param list<string> $allowedAttributes
     */
    private static function sanitizeNode(Node $node, array $allowedTags, array $allowedAttributes): void
    {
        $toRemove = [];

        /** @var mixed $child */
        foreach ($node->childNodes as $child) {
            if ($child instanceof \Dom\Element) {
                /** @var mixed $localName */
                $localName = $child->localName;
                $tagName = strtolower(is_string($localName) ? $localName : '');

                if (!in_array($tagName, $allowedTags, true)) {
                    $toRemove[] = $child;

                    continue;
                }

                // Remove disallowed attributes
                self::sanitizeAttributes($child, $allowedAttributes);

                // Recurse into children
                self::sanitizeNode($child, $allowedTags, $allowedAttributes);
            }
        }

        foreach ($toRemove as $remove) {
            $node->removeChild($remove);
        }
    }

    /**
     * Remove disallowed attributes from an element.
     *
     * @param list<string> $allowedAttributes
     */
    private static function sanitizeAttributes(\Dom\Element $element, array $allowedAttributes): void
    {
        $hasDataWildcard = in_array('data-*', $allowedAttributes, true);
        $toRemove = [];

        /** @var mixed $attr */
        foreach ($element->attributes as $attr) {
            if (!$attr instanceof \Dom\Attr) {
                continue;
            }

            /** @var mixed $attrName */
            $attrName = $attr->name;
            $attrNameStr = is_string($attrName) ? $attrName : '';
            $name = strtolower($attrNameStr);

            // Block event handlers
            if (str_starts_with($name, 'on')) {
                $toRemove[] = $attrNameStr;

                continue;
            }

            // Allow data-* attributes if wildcard is present
            if ($hasDataWildcard && str_starts_with($name, 'data-')) {
                continue;
            }

            if (!in_array($name, $allowedAttributes, true)) {
                $toRemove[] = $attrNameStr;

                continue;
            }

            // Block javascript: URIs in href/src
            /** @var mixed $attrValue */
            $attrValue = $attr->value;
            if (($name === 'href' || $name === 'src') && self::isDangerousUri(is_string($attrValue) ? $attrValue : '')) {
                $toRemove[] = $attrNameStr;
            }
        }

        foreach ($toRemove as $attrName) {
            $element->removeAttribute($attrName);
        }
    }

    /**
     * Check if a URI uses a dangerous scheme.
     */
    private static function isDangerousUri(string $uri): bool
    {
        $normalized = strtolower(trim($uri));

        return str_starts_with($normalized, 'javascript:')
            || str_starts_with($normalized, 'vbscript:')
            || str_starts_with($normalized, 'data:text/html');
    }
}
