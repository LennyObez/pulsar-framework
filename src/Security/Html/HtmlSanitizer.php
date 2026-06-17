<?php

declare(strict_types=1);

namespace Pulsar\Security\Html;

use Closure;
use DOMAttr;
use DOMCdataSection;
use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;
use Pulsar\Api\Api;

use function array_any;
use function array_reverse;
use function filter_var;
use function html_entity_decode;
use function htmlspecialchars;
use function in_array;
use function iterator_to_array;
use function mb_convert_encoding;
use function mb_detect_encoding;
use function preg_match;
use function preg_replace;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function urldecode;

use const ENT_HTML5;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const FILTER_VALIDATE_EMAIL;
use const LIBXML_HTML_NODEFDTD;
use const LIBXML_HTML_NOIMPLIED;
use const LIBXML_NOERROR;
use const LIBXML_NONET;

/**
 * Allowlist-based, DOM-driven HTML sanitizer for untrusted user-authored markup.
 *
 * A single, framework-wide security primitive (CMS content, forum posts,
 * comments all share it) implementing a 7-step algorithm: input
 * canonicalization, DOM parsing, tree walk (drop disallowed elements), attribute
 * allowlisting, URL-scheme validation, dangerous-construct removal, and
 * serialization with a defense-in-depth pattern scan that falls back to escaped
 * plaintext on any residue. Behaviour is driven entirely by {@see HtmlSanitizerPolicy}.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HtmlSanitizer
{
    /**
     * @param (Closure(string): void)|null $onBypass Invoked with the original input when the
     *        defense-in-depth scan trips and output is escaped to plaintext (e.g. for audit).
     */
    public function __construct(
        private HtmlSanitizerPolicy $policy,
        private ?Closure $onBypass = null,
    ) {}

    /**
     * Sanitize untrusted HTML into markup safe for rendering.
     */
    public function sanitize(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $allowlist = $this->policy->allowedElements;

        // Step 1: input canonicalization
        $canonicalized = $this->canonicalizeInput($html);

        if ($canonicalized === null) {
            return $this->escapeToPlaintext($html);
        }

        // Step 2: DOM parsing
        $dom = $this->parseDom($canonicalized);

        if ($dom === null) {
            return $this->escapeToPlaintext($canonicalized);
        }

        // Step 3: tree walk (depth-first, bottom-up)
        $this->walkTree($dom, $allowlist);

        // Step 4: attribute filtering
        $this->filterAttributes($dom, $allowlist);

        // Step 5: URL sanitization
        $this->sanitizeUrls($dom, $allowlist);

        // Step 6: dangerous construct removal
        $this->removeDangerousConstructs($dom, $allowlist);

        // Step 7: serialization + final validation
        return $this->serializeAndValidate($dom, $html);
    }

    /**
     * Step 1: Canonicalize input to a safe UTF-8 string (null if unconvertible).
     */
    private function canonicalizeInput(string $html): ?string
    {
        $encoding = mb_detect_encoding($html, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);

        if ($encoding === false) {
            return null;
        }

        if ($encoding !== 'UTF-8') {
            $converted = mb_convert_encoding($html, 'UTF-8', $encoding);

            if ($converted === false) {
                return null;
            }

            $html = $converted;
        }

        if (str_starts_with($html, "\xEF\xBB\xBF")) {
            $html = substr($html, 3);
        }

        $html = str_replace("\x00", '', $html);

        // Remove BiDi control characters: U+202A–U+202E, U+2066–U+2069
        $html = preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $html) ?? $html;

        // Strip CDATA markers before DOM parsing (not valid in HTML).
        return str_replace(['<![CDATA[', ']]>'], '', $html);
    }

    /**
     * Step 2: Parse HTML into a DOMDocument (null on failure).
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
     * Step 3: Walk DOM depth-first, bottom-up, removing disallowed elements
     * (unwrapping their children), comments, PIs and CDATA sections.
     *
     * @param array<string, list<string>> $allowlist
     */
    private function walkTree(DOMDocument $dom, array $allowlist): void
    {
        $nodes = [];
        $this->collectNodes($dom->documentElement, $nodes);

        foreach (array_reverse($nodes) as $node) {
            if ($node instanceof DOMComment
                || $node instanceof DOMProcessingInstruction
                || $node instanceof DOMCdataSection
            ) {
                $node->parentNode?->removeChild($node);

                continue;
            }

            if ($node instanceof DOMElement) {
                $tagName = strtolower($node->tagName);

                if ($tagName === 'div' || $tagName === 'html' || $tagName === 'body') {
                    continue;
                }

                if (!isset($allowlist[$tagName])) {
                    $this->unwrapElement($node);
                }
            }
        }
    }

    /**
     * Recursively collect all descendant nodes for bottom-up processing.
     *
     * @param list<DOMNode> $nodes Collected nodes (by reference)
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

    /**
     * Unwrap an element: hoist its children to its parent, then remove it.
     */
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
     * Step 4: Filter attributes on allowed elements to their specific allowlists.
     *
     * @param array<string, list<string>> $allowlist
     */
    private function filterAttributes(DOMDocument $dom, array $allowlist): void
    {
        $elements = $dom->getElementsByTagName('*');

        /** @var DOMElement $element */
        foreach (iterator_to_array($elements) as $element) {
            $tagName = strtolower($element->tagName);

            if (!isset($allowlist[$tagName])) {
                continue;
            }

            $allowedAttrs = $allowlist[$tagName];
            $toRemove = [];

            $attributes = $element->attributes;

            if ($attributes !== null) {
                /** @var DOMAttr $attr */
                foreach (iterator_to_array($attributes) as $attr) {
                    if (!in_array(strtolower($attr->name), $allowedAttrs, true)) {
                        $toRemove[] = $attr->name;
                    }
                }
            }

            foreach ($toRemove as $attrName) {
                $element->removeAttribute($attrName);
            }

            // Validate class on <code>: only /^language-[a-z0-9-]+$/
            if ($tagName === 'code' && $element->hasAttribute('class')) {
                $classValue = $element->getAttribute('class');

                if (preg_match('/^language-[a-z0-9-]+$/', $classValue) !== 1) {
                    $element->removeAttribute('class');
                }
            }
        }
    }

    /**
     * Step 5: Sanitize URL attributes (href on <a>, src on <img>).
     *
     * @param array<string, list<string>> $allowlist
     */
    private function sanitizeUrls(DOMDocument $dom, array $allowlist): void
    {
        $elements = iterator_to_array($dom->getElementsByTagName('*'));

        /** @var DOMElement $element */
        foreach ($elements as $element) {
            $tagName = strtolower($element->tagName);

            if (!isset($allowlist[$tagName])) {
                continue;
            }

            if ($tagName === 'a' && $element->hasAttribute('href')) {
                $href = $element->getAttribute('href');

                if (!$this->isValidHref($href)) {
                    $element->removeAttribute('href');
                    $this->unwrapElement($element);

                    continue;
                }

                if ($this->policy->forceRelOnAbsoluteLinks && $this->hasScheme($href)) {
                    $element->setAttribute('rel', 'noopener noreferrer');
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

    /**
     * Validate an href value for use on <a> elements.
     */
    private function isValidHref(string $href): bool
    {
        $normalized = $this->normalizeUrlWhitespace($this->decodeUrl($href));

        if ($normalized === '') {
            return false;
        }

        $scheme = $this->extractScheme($normalized);

        if ($scheme === null) {
            return true; // relative URL
        }

        $schemeLower = strtolower($scheme);

        if (!in_array($schemeLower, $this->policy->hrefSchemes, true)) {
            return false;
        }

        if ($schemeLower === 'mailto') {
            return $this->isValidMailto($normalized);
        }

        return true;
    }

    /**
     * Validate a src value for use on <img> elements.
     */
    private function isValidImgSrc(string $src): bool
    {
        $normalized = $this->normalizeUrlWhitespace($this->decodeUrl($src));

        if ($normalized === '') {
            return false;
        }

        $scheme = $this->extractScheme($normalized);

        if ($scheme === null) {
            if (!$this->policy->allowRelativeImg) {
                return false;
            }

            return $this->policy->imgRelativePrefix === ''
                || str_starts_with($normalized, $this->policy->imgRelativePrefix);
        }

        $schemeLower = strtolower($scheme);

        if ($schemeLower === 'data') {
            return $this->policy->allowDataImages && $this->isValidImageDataUri($normalized);
        }

        return in_array($schemeLower, $this->policy->imgSchemes, true);
    }

    /**
     * Validate a data URI as a safe image under the size limit.
     */
    private function isValidImageDataUri(string $uri): bool
    {
        if (preg_match('#^data:image/(png|jpeg|gif|webp);base64,([A-Za-z0-9+/=]+)$#', $uri, $matches) !== 1) {
            return false;
        }

        return strlen($matches[2]) <= $this->policy->maxDataUriBytes;
    }

    /**
     * Decode a URL value to defeat double-encoding attacks.
     */
    private function decodeUrl(string $url): string
    {
        return html_entity_decode(urldecode($url), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Strip all whitespace, tabs, newlines, and null bytes from a URL.
     */
    private function normalizeUrlWhitespace(string $url): string
    {
        return str_replace(["\t", "\n", "\r", "\0", "\x0B", ' '], '', $url);
    }

    /**
     * Extract the scheme from a URL, or null if none.
     */
    private function extractScheme(string $url): ?string
    {
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $url, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Whether a URL has an explicit scheme.
     */
    private function hasScheme(string $url): bool
    {
        return $this->extractScheme($url) !== null;
    }

    /**
     * Validate a mailto: URL contains a syntactically valid email address.
     */
    private function isValidMailto(string $url): bool
    {
        return filter_var(substr($url, 7), FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Step 6: Remove additional dangerous constructs (defense-in-depth).
     *
     * @param array<string, list<string>> $allowlist
     */
    private function removeDangerousConstructs(DOMDocument $dom, array $allowlist): void
    {
        $elements = iterator_to_array($dom->getElementsByTagName('*'));

        /** @var DOMElement $element */
        foreach ($elements as $element) {
            $tagName = strtolower($element->tagName);
            $elementAllowedAttrs = $allowlist[$tagName] ?? [];
            $toRemove = [];

            $attributes = $element->attributes;

            if ($attributes === null) {
                continue;
            }

            /** @var DOMAttr $attr */
            foreach (iterator_to_array($attributes) as $attr) {
                $attrNameLower = strtolower($attr->name);

                if (in_array($attrNameLower, $elementAllowedAttrs, true)) {
                    continue;
                }

                if ($attrNameLower === 'style'
                    || str_starts_with($attrNameLower, 'on')
                    || str_starts_with($attrNameLower, 'data-')
                    || in_array($attrNameLower, $this->policy->dangerousAttributes, true)
                    || $attrNameLower === 'class'
                ) {
                    $toRemove[] = $attr->name;
                }
            }

            foreach ($toRemove as $attrName) {
                $element->removeAttribute($attrName);
            }
        }
    }

    /**
     * Step 7: Serialize the DOM and apply final validation.
     */
    private function serializeAndValidate(DOMDocument $dom, string $originalHtml): string
    {
        $html = $dom->saveHTML();

        if ($html === false) {
            return $this->escapeToPlaintext($originalHtml);
        }

        $html = $this->stripWrapperDiv($html);
        $html = $this->stripHtmlBodyWrapper($html);

        // Normalize self-closing tags to HTML5 syntax
        $html = preg_replace('#<(br|hr|img)([^>]*)\s*/>#i', '<$1$2>', $html) ?? $html;

        // Defense-in-depth: strip residual CDATA + processing instructions
        $html = str_replace('<![CDATA[', '', $html);
        $html = (string) preg_replace('/<\?[^>]*\?>/', '', $html);

        if ($this->containsDangerousPatterns($html)) {
            if ($this->onBypass !== null) {
                ($this->onBypass)($originalHtml);
            }

            return $this->escapeToPlaintext($originalHtml);
        }

        return trim($html);
    }

    /**
     * Strip the wrapper <div> added during DOM parsing.
     */
    private function stripWrapperDiv(string $html): string
    {
        if (preg_match('#^<div>(.*)</div>$#s', $html, $matches) === 1) {
            return $matches[1];
        }

        return $html;
    }

    /**
     * Strip the <html><body> wrapper that DOMDocument may add.
     */
    private function stripHtmlBodyWrapper(string $html): string
    {
        $html = (string) preg_replace('#^<!DOCTYPE[^>]*>#i', '', $html);
        $html = (string) preg_replace('#</?html[^>]*>#i', '', $html);
        $html = (string) preg_replace('#</?body[^>]*>#i', '', $html);
        $html = (string) preg_replace('#</?head[^>]*>#i', '', $html);

        return trim($html);
    }

    /**
     * Scan serialized HTML for known-dangerous patterns (defense-in-depth).
     */
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
        ];

        return array_any($patterns, static fn(string $pattern): bool => preg_match($pattern, $html) === 1);
    }

    /**
     * Escape raw input to safe plaintext as a fallback.
     */
    private function escapeToPlaintext(string $input): string
    {
        return htmlspecialchars($input);
    }
}
