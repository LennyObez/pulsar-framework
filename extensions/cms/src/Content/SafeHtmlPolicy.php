<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use DOMAttr;
use DOMCdataSection;
use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;
use Pulsar\Api\Api;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function in_array;
use function strlen;

/**
 * Allowlist-based HTML sanitizer for user-authored CMS content.
 *
 * Implements a 7-step sanitization algorithm: input canonicalization, DOM parsing,
 * tree walk, attribute filtering, URL sanitization, dangerous construct removal,
 * and final serialization with defense-in-depth validation.
 */
#[Api(since: '1.0.0')]
final readonly class SafeHtmlPolicy
{
    /**
     * Exhaustive element-to-allowed-attributes allowlist.
     *
     * @var array<string, list<string>>
     */
    private const ELEMENT_ATTRIBUTES = [
        'p' => [],
        'br' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'h5' => [],
        'h6' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'blockquote' => [],
        'pre' => [],
        'code' => ['class'],
        'strong' => [],
        'em' => [],
        'a' => ['href', 'rel', 'title'],
        'img' => ['src', 'alt', 'width', 'height', 'loading'],
        'figure' => [],
        'figcaption' => [],
        'table' => [],
        'thead' => [],
        'tbody' => [],
        'tr' => [],
        'th' => ['scope', 'colspan', 'rowspan'],
        'td' => ['colspan', 'rowspan'],
        'dl' => [],
        'dt' => [],
        'dd' => [],
        'abbr' => ['title'],
        'mark' => [],
        'sub' => [],
        'sup' => [],
        'hr' => [],
        'details' => ['open'],
        'summary' => [],
        'time' => ['datetime'],
    ];

    /**
     * Subset of elements allowed in comment bodies.
     *
     * @var array<string, list<string>>
     */
    private const COMMENT_ATTRIBUTES = [
        'p' => [],
        'br' => [],
        'strong' => [],
        'em' => [],
        'a' => ['href', 'rel', 'title'],
        'code' => [],
        'blockquote' => [],
        'pre' => [],
    ];

    /**
     * Dangerous attribute names stripped regardless of element in Step 6.
     *
     * @var list<string>
     */
    private const DANGEROUS_ATTRIBUTES = [
        'style',
        'id',
        'srcset',
        'formaction',
        'xlink:href',
        'xmlns',
        'xml:base',
    ];

    /** Maximum data URI size in bytes (32 KB). */
    private const MAX_DATA_URI_BYTES = 32768;

    public function __construct(
        private AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * Sanitize user-authored HTML for content body storage.
     *
     * @param string $html Raw HTML input
     *
     * @return string Sanitized HTML safe for rendering
     */
    public function sanitize(string $html): string
    {
        return $this->doSanitize($html, self::ELEMENT_ATTRIBUTES);
    }

    /**
     * Sanitize HTML for comment bodies using the strict comment subset.
     *
     * @param string $html Raw HTML input
     *
     * @return string Sanitized HTML safe for rendering in comments
     */
    public function sanitizeComment(string $html): string
    {
        return $this->doSanitize($html, self::COMMENT_ATTRIBUTES);
    }

    /**
     * Core sanitization pipeline shared by both public methods.
     *
     * @param string $html Raw input
     * @param array<string, list<string>> $allowlist Element-attribute allowlist
     *
     * @return string Sanitized HTML
     */
    private function doSanitize(string $html, array $allowlist): string
    {
        if ($html === '') {
            return '';
        }

        // Step 1 — Input canonicalization
        $canonicalized = $this->canonicalizeInput($html);

        if ($canonicalized === null) {
            return $this->escapeToPlaintext($html);
        }

        // Step 2 — DOM parsing
        $dom = $this->parseDom($canonicalized);

        if ($dom === null) {
            return $this->escapeToPlaintext($canonicalized);
        }

        // Step 3 — Tree walk (depth-first, bottom-up)
        $this->walkTree($dom, $allowlist);

        // Step 4 — Attribute filtering
        $this->filterAttributes($dom, $allowlist);

        // Step 5 — URL sanitization
        $this->sanitizeUrls($dom, $allowlist);

        // Step 6 — Dangerous construct removal
        $this->removeDangerousConstructs($dom, $allowlist);

        // Step 7 — Serialization + final validation
        return $this->serializeAndValidate($dom, $html);
    }

    /**
     * Step 1 — Canonicalize input to a safe UTF-8 string.
     *
     * Returns null if the input cannot be converted to UTF-8.
     */
    private function canonicalizeInput(string $html): ?string
    {
        // Convert to UTF-8 if not already
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

        // Strip UTF-8 BOM
        if (str_starts_with($html, "\xEF\xBB\xBF")) {
            $html = substr($html, 3);
        }

        // Remove null bytes
        $html = str_replace("\x00", '', $html);

        // Remove BiDi control characters: U+202A–U+202E, U+2066–U+2069
        $html = preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $html) ?? $html;

        // Note: entity decoding is NOT applied to the full input here — doing so would
        // convert escaped markup (e.g. &lt;script&gt;) into live elements before DOM parsing.
        // Entity-encoded javascript: bypasses in URL attributes are handled in decodeUrl()
        // during the URL sanitization step (Step 5).

        return $html;
    }

    /**
     * Step 2 — Parse HTML into a DOMDocument.
     *
     * Returns null on parse failure.
     */
    private function parseDom(string $html): ?DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');

        // Wrap in a container with charset meta to handle UTF-8 without deprecated HTML-ENTITIES encoding
        $wrapped = '<?xml encoding="UTF-8"><div>' . $html . '</div>';

        $success = $dom->loadHTML(
            $wrapped,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR,
        );

        if (!$success) {
            return null;
        }

        // Remove the XML processing instruction we added for encoding
        foreach (iterator_to_array($dom->childNodes) as $child) {
            if ($child instanceof DOMProcessingInstruction) {
                $dom->removeChild($child);
            }
        }

        return $dom;
    }

    /**
     * Step 3 — Walk DOM tree depth-first, bottom-up.
     *
     * Removes disallowed elements (unwrapping children), comment nodes,
     * processing instructions, and CDATA sections.
     *
     * @param array<string, list<string>> $allowlist
     */
    private function walkTree(DOMDocument $dom, array $allowlist): void
    {
        // Collect all nodes first to avoid modifying tree during iteration
        $nodes = [];
        $this->collectNodes($dom->documentElement, $nodes);

        // Process bottom-up (reverse order preserves child indices)
        foreach (array_reverse($nodes) as $node) {
            if ($node instanceof DOMComment) {
                $node->parentNode?->removeChild($node);

                continue;
            }

            if ($node instanceof DOMProcessingInstruction) {
                $node->parentNode?->removeChild($node);

                continue;
            }

            if ($node instanceof DOMCdataSection) {
                $node->parentNode?->removeChild($node);

                continue;
            }

            if ($node instanceof DOMElement) {
                $tagName = strtolower($node->tagName);

                // Skip wrapper div and structural elements added by parser
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
            $this->collectNodes($child, $nodes);
            $nodes[] = $child;
        }
    }

    /**
     * Unwrap an element: move its children to its parent, then remove it.
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
     * Step 4 — Filter attributes on allowed elements to their specific allowlists.
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

            /** @var DOMAttr $attr */
            foreach (iterator_to_array($element->attributes) as $attr) {
                if (!in_array(strtolower($attr->name), $allowedAttrs, true)) {
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
     * Step 5 — Sanitize URL attributes (href and src).
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

            // Process href on <a>
            if ($tagName === 'a' && $element->hasAttribute('href')) {
                $href = $element->getAttribute('href');

                if (!$this->isValidHref($href)) {
                    $element->removeAttribute('href');
                    $this->unwrapElement($element);

                    continue;
                }

                // Force rel="noopener noreferrer" on absolute-URL links
                if ($this->hasScheme($href)) {
                    $element->setAttribute('rel', 'noopener noreferrer');
                }
            }

            // Process src on <img>
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
        $decoded = $this->decodeUrl($href);
        $normalized = $this->normalizeUrlWhitespace($decoded);

        if ($normalized === '') {
            return false;
        }

        $scheme = $this->extractScheme($normalized);

        if ($scheme === null) {
            // Relative URL — allowed
            return true;
        }

        $schemeLower = strtolower($scheme);

        if (in_array($schemeLower, ['http', 'https'], true)) {
            return true;
        }

        if ($schemeLower === 'mailto') {
            return $this->isValidMailto($normalized);
        }

        return false;
    }

    /**
     * Validate a src value for use on <img> elements.
     */
    private function isValidImgSrc(string $src): bool
    {
        $decoded = $this->decodeUrl($src);
        $normalized = $this->normalizeUrlWhitespace($decoded);

        if ($normalized === '') {
            return false;
        }

        $scheme = $this->extractScheme($normalized);

        if ($scheme === null) {
            // Relative URL — must start with /media/
            return str_starts_with($normalized, '/media/');
        }

        $schemeLower = strtolower($scheme);

        if ($schemeLower === 'https') {
            return true;
        }

        // Allow safe data URIs for images under 32KB
        if ($schemeLower === 'data') {
            return $this->isValidImageDataUri($normalized);
        }

        return false;
    }

    /**
     * Validate a data URI as a safe image under the size limit.
     */
    private function isValidImageDataUri(string $uri): bool
    {
        if (!preg_match('#^data:image/(png|jpeg|gif|webp);base64,([A-Za-z0-9+/=]+)$#', $uri, $matches)) {
            return false;
        }

        $base64Data = $matches[2];

        return strlen($base64Data) <= self::MAX_DATA_URI_BYTES;
    }

    /**
     * Decode a URL value to defeat double-encoding attacks.
     */
    private function decodeUrl(string $url): string
    {
        $decoded = urldecode($url);

        return html_entity_decode($decoded, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Strip all whitespace, tabs, newlines, and null bytes from a URL.
     */
    private function normalizeUrlWhitespace(string $url): string
    {
        return str_replace(["\t", "\n", "\r", "\0", "\x0B", ' '], '', $url);
    }

    /**
     * Extract the scheme from a URL, or null if no scheme.
     */
    private function extractScheme(string $url): ?string
    {
        // Match scheme: letters followed by colon
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Check whether a URL has an explicit scheme.
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
        $email = substr($url, 7); // Strip "mailto:"

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Step 6 — Remove additional dangerous constructs as defense-in-depth.
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

            /** @var DOMAttr $attr */
            foreach (iterator_to_array($element->attributes) as $attr) {
                $attrNameLower = strtolower($attr->name);

                // Skip attributes that are explicitly in this element's allowlist
                if (in_array($attrNameLower, $elementAllowedAttrs, true)) {
                    continue;
                }

                // Strip style attributes
                if ($attrNameLower === 'style') {
                    $toRemove[] = $attr->name;

                    continue;
                }

                // Strip on* event handlers (but not allowlisted attributes like "open")
                if (str_starts_with($attrNameLower, 'on')) {
                    $toRemove[] = $attr->name;

                    continue;
                }

                // Strip data-* attributes
                if (str_starts_with($attrNameLower, 'data-')) {
                    $toRemove[] = $attr->name;

                    continue;
                }

                // Strip dangerous named attributes
                if (in_array($attrNameLower, self::DANGEROUS_ATTRIBUTES, true)) {
                    $toRemove[] = $attr->name;

                    continue;
                }

                // Strip class except on <code> with valid pattern
                if ($attrNameLower === 'class') {
                    $toRemove[] = $attr->name;
                }
            }

            foreach ($toRemove as $attrName) {
                $element->removeAttribute($attrName);
            }
        }
    }

    /**
     * Step 7 — Serialize the DOM and apply final validation.
     *
     * @param string $originalHtml Original input for fallback escaping
     */
    private function serializeAndValidate(DOMDocument $dom, string $originalHtml): string
    {
        $html = $dom->saveHTML();

        if ($html === false) {
            return $this->escapeToPlaintext($originalHtml);
        }

        // Strip the wrapper div we added in parseDom
        $html = $this->stripWrapperDiv($html);

        // Strip <html><body> wrapper that DOMDocument may add
        $html = $this->stripHtmlBodyWrapper($html);

        // Normalize self-closing tags to HTML5 syntax
        $html = preg_replace('#<(br|hr|img)([^>]*)\s*/>#i', '<$1$2>', $html) ?? $html;

        // Defense-in-depth: remove CDATA sections and processing instructions from serialized output
        $html = str_replace('<![CDATA[', '', $html);
        $html = (string) preg_replace('/<\?[^>]*\?>/', '', $html);

        // Final regex scan for dangerous patterns
        if ($this->containsDangerousPatterns($html)) {
            $this->auditLogger->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Failure,
                null,
                'cms.security.sanitizer_bypass_detected',
                'SafeHtmlPolicy',
                ['input_length' => strlen($originalHtml)],
            );

            return $this->escapeToPlaintext($originalHtml);
        }

        return trim($html);
    }

    /**
     * Strip the wrapper <div> added during DOM parsing.
     */
    private function stripWrapperDiv(string $html): string
    {
        // Remove outermost <div>...</div> wrapper
        if (preg_match('#^<div>(.*)</div>$#s', $html, $matches)) {
            return $matches[1];
        }

        return $html;
    }

    /**
     * Strip the <html><body> wrapper that DOMDocument adds.
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

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Escape raw input to safe plaintext as a fallback.
     */
    private function escapeToPlaintext(string $input): string
    {
        return htmlspecialchars($input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
