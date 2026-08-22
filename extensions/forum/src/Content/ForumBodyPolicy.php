<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Content;

use DOMAttr;
use DOMCdataSection;
use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;
use Pulsar\Api\Api;

use function array_reverse;
use function html_entity_decode;
use function htmlspecialchars;
use function in_array;
use function iterator_to_array;
use function preg_match;
use function preg_replace;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;
use function urldecode;

/**
 * Allowlist-based HTML sanitizer for forum post bodies.
 *
 * More permissive than CMS comment policy (allows code blocks, images, tables)
 * but less than CMS content policy (no scripts, iframes, embeds, SVG, or MathML).
 *
 * Uses a 5-step pipeline: DOM parsing, tree walk, attribute filtering,
 * URL sanitization, and serialization with defense-in-depth validation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ForumBodyPolicy
{
    /**
     * Exhaustive element-to-allowed-attributes allowlist for forum posts.
     *
     * @var array<string, list<string>>
     */
    private const array ELEMENT_ATTRIBUTES = [
        'p' => [],
        'br' => [],
        'h1' => ['id', 'class'],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'blockquote' => [],
        'pre' => [],
        'code' => ['class'],
        'strong' => [],
        'em' => [],
        'del' => [],
        'a' => ['href', 'rel', 'title'],
        'img' => ['src', 'alt', 'width', 'height'],
        'table' => [],
        'thead' => [],
        'tbody' => [],
        'tr' => [],
        'th' => ['scope', 'colspan', 'rowspan'],
        'td' => ['colspan', 'rowspan'],
        'hr' => [],
    ];

    /**
     * Sanitize HTML for forum post body storage.
     *
     * @param string $html Raw HTML (typically from MarkdownRenderer output)
     * @return string Sanitized HTML safe for rendering
     */
    public function sanitize(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $html = $this->canonicalizeInput($html);
        $dom = $this->parseDom($html);

        if ($dom === null) {
            return $this->escapeToPlaintext($html);
        }

        $this->walkTree($dom);
        $this->filterAttributes($dom);
        $this->sanitizeUrls($dom);

        return $this->serializeAndValidate($dom, $html);
    }

    /**
     * Canonicalize input to safe UTF-8.
     */
    private function canonicalizeInput(string $html): string
    {
        // Strip UTF-8 BOM
        if (str_starts_with($html, "\xEF\xBB\xBF")) {
            $html = substr($html, 3);
        }

        // Remove null bytes
        $html = str_replace("\x00", '', $html);

        // Remove BiDi control characters
        $html = preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $html) ?? $html;

        // Strip CDATA markers
        return str_replace(['<![CDATA[', ']]>'], '', $html);
    }

    /**
     * Parse HTML into a DOMDocument.
     */
    private function parseDom(string $html): ?DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"><div>' . $html . '</div>';

        $success = $dom->loadHTML(
            $wrapped,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR,
        );

        if (!$success) {
            return null;
        }

        /** @var mixed $child */
        foreach (iterator_to_array($dom->childNodes) as $child) {
            if ($child instanceof DOMProcessingInstruction) {
                $dom->removeChild($child);
            }
        }

        return $dom;
    }

    /**
     * Walk DOM tree depth-first, bottom-up: remove disallowed elements.
     */
    private function walkTree(DOMDocument $dom): void
    {
        $nodes = [];
        $this->collectNodes($dom->documentElement, $nodes);

        foreach (array_reverse($nodes) as $node) {
            if ($node instanceof DOMComment || $node instanceof DOMProcessingInstruction || $node instanceof DOMCdataSection) {
                $node->parentNode?->removeChild($node);

                continue;
            }

            if ($node instanceof DOMElement) {
                $tagName = strtolower($node->tagName);

                if ($tagName === 'div' || $tagName === 'html' || $tagName === 'body') {
                    continue;
                }

                if (!isset(self::ELEMENT_ATTRIBUTES[$tagName])) {
                    $this->unwrapElement($node);
                }
            }
        }
    }

    /**
     * @param list<DOMNode> $nodes
     */
    private function collectNodes(?DOMNode $node, array &$nodes): void
    {
        if ($node === null) {
            return;
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof DOMNode) {
                continue;
            }

            $this->collectNodes($child, $nodes);
            $nodes[] = $child;
        }
    }

    private function unwrapElement(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    /**
     * Filter attributes on allowed elements to their specific allowlists.
     */
    private function filterAttributes(DOMDocument $dom): void
    {
        $elements = $dom->getElementsByTagName('*');

        /** @var DOMElement $element */
        foreach (iterator_to_array($elements) as $element) {
            $tagName = strtolower($element->tagName);

            if (!isset(self::ELEMENT_ATTRIBUTES[$tagName])) {
                continue;
            }

            $allowedAttrs = self::ELEMENT_ATTRIBUTES[$tagName];
            $toRemove = [];
            $attributes = $element->attributes;

            if ($attributes === null) {
                continue;
            }

            /** @var DOMAttr $attr */
            foreach (iterator_to_array($attributes) as $attr) {
                $attrNameLower = strtolower($attr->name);

                // Strip on* event handlers
                if (str_starts_with($attrNameLower, 'on')) {
                    $toRemove[] = $attr->name;

                    continue;
                }

                // Strip data-* attributes
                if (str_starts_with($attrNameLower, 'data-')) {
                    $toRemove[] = $attr->name;

                    continue;
                }

                if (!in_array($attrNameLower, $allowedAttrs, true)) {
                    $toRemove[] = $attr->name;
                }
            }

            foreach ($toRemove as $attrName) {
                $element->removeAttribute($attrName);
            }

            // Validate class on <code>: only /^language-[a-z0-9-]+$/
            if ($tagName === 'code' && $element->hasAttribute('class')) {
                $classValue = $element->getAttribute('class');

                if (!preg_match('/^language-[a-z0-9-]+$/', $classValue)) {
                    $element->removeAttribute('class');
                }
            }
        }
    }

    /**
     * Sanitize URL attributes (href and src).
     */
    private function sanitizeUrls(DOMDocument $dom): void
    {
        $elements = iterator_to_array($dom->getElementsByTagName('*'));

        /** @var DOMElement $element */
        foreach ($elements as $element) {
            $tagName = strtolower($element->tagName);

            if ($tagName === 'a' && $element->hasAttribute('href')) {
                $href = $element->getAttribute('href');

                if (!$this->isValidHref($href)) {
                    $this->unwrapElement($element);

                    continue;
                }

                if ($this->hasScheme($href)) {
                    $element->setAttribute('rel', 'noopener noreferrer nofollow');
                }
            }

            if ($tagName === 'img' && $element->hasAttribute('src')) {
                $src = $element->getAttribute('src');

                if (!$this->isValidImgSrc($src)) {
                    $element->parentNode?->removeChild($element);
                }
            }
        }
    }

    private function isValidHref(string $href): bool
    {
        $decoded = html_entity_decode(urldecode($href), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $normalized = str_replace(["\t", "\n", "\r", "\0", "\x0B", ' '], '', $decoded);

        if ($normalized === '') {
            return false;
        }

        $scheme = $this->extractScheme($normalized);

        if ($scheme === null) {
            return true;
        }

        return in_array(strtolower($scheme), ['http', 'https', 'mailto'], true);
    }

    private function isValidImgSrc(string $src): bool
    {
        $decoded = html_entity_decode(urldecode($src), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $normalized = str_replace(["\t", "\n", "\r", "\0", "\x0B", ' '], '', $decoded);

        if ($normalized === '') {
            return false;
        }

        $scheme = $this->extractScheme($normalized);

        if ($scheme === null) {
            return true;
        }

        return strtolower($scheme) === 'https';
    }

    private function extractScheme(string $url): ?string
    {
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function hasScheme(string $url): bool
    {
        return $this->extractScheme($url) !== null;
    }

    /**
     * Serialize and validate with defense-in-depth.
     *
     * Uses DOM-aware extraction via saveHTML($node) on the body element
     * to avoid greedy regex stripping of wrapper tags.
     */
    private function serializeAndValidate(DOMDocument $dom, string $originalHtml): string
    {
        // Extract content from <body> using DOM instead of regex stripping
        $body = $dom->getElementsByTagName('body')->item(0);

        if ($body !== null) {
            $html = '';

            /** @var mixed $child */
            foreach ($body->childNodes as $child) {
                if (!$child instanceof DOMNode) {
                    continue;
                }

                $fragment = $dom->saveHTML($child);

                if ($fragment !== false) {
                    $html .= $fragment;
                }
            }
        } else {
            $html = $dom->saveHTML();

            if ($html === false) {
                return $this->escapeToPlaintext($originalHtml);
            }
        }

        // Remove CDATA and PIs from output
        $html = str_replace('<![CDATA[', '', $html);
        $html = (string) preg_replace('/<\?[^>]*\?>/', '', $html);

        // Defense-in-depth: check for dangerous patterns
        if ($this->containsDangerousPatterns($html)) {
            return $this->escapeToPlaintext($originalHtml);
        }

        return trim($html);
    }

    private function containsDangerousPatterns(string $html): bool
    {
        $patterns = [
            '/j\s*a\s*v\s*a\s*s\s*c\s*r\s*i\s*p\s*t\s*:/i',
            '/v\s*b\s*s\s*c\s*r\s*i\s*p\s*t\s*:/i',
            '/expression\s*\(/i',
            '/<\s*script/i',
            '/\bon[a-z]+\s*=/i',
            '/<\s*\/?\s*svg[\s>]/i',
            '/<\s*\/?\s*math[\s>]/i',
            '/<\s*\/?\s*iframe[\s>]/i',
            '/<\s*\/?\s*object[\s>]/i',
            '/<\s*\/?\s*embed[\s>]/i',
        ];

        return array_any($patterns, static fn(string $pattern): bool => preg_match($pattern, $html) === 1);
    }

    private function escapeToPlaintext(string $input): string
    {
        return htmlspecialchars($input);
    }
}
