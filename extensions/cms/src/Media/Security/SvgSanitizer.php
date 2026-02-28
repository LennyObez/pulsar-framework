<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Security;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;
use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Exception\CmsException;

use function in_array;
use function iterator_to_array;
use function preg_match;
use function preg_replace;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * DOMDocument-based SVG sanitizer using a strict element/attribute allowlist.
 *
 * Performs a depth-first tree walk to remove disallowed elements entirely
 * and strip disallowed attributes. Blocks all script execution, external
 * resource loading, and event handlers.
 */
#[Api(since: '1.0.0')]
final readonly class SvgSanitizer
{
    /**
     * Allowed SVG elements.
     *
     * @var list<string>
     */
    private const array ALLOWED_ELEMENTS = [
        'svg',
        'g',
        'path',
        'circle',
        'rect',
        'line',
        'polyline',
        'polygon',
        'ellipse',
        'text',
        'tspan',
        'textpath',
        'defs',
        'clippath',
        'mask',
        'use',
        'lineargradient',
        'radialgradient',
        'stop',
        'symbol',
        'title',
        'desc',
        'marker',
        'pattern',
        'image',
        'filter',
        'fegaussianblur',
        'feoffset',
        'femerge',
        'femergenode',
        'fecolormatrix',
        'fecomposite',
        'feflood',
        'feblend',
    ];

    /**
     * Allowed SVG attributes.
     *
     * @var list<string>
     */
    private const array ALLOWED_ATTRIBUTES = [
        'd',
        'x',
        'y',
        'cx',
        'cy',
        'r',
        'rx',
        'ry',
        'x1',
        'y1',
        'x2',
        'y2',
        'points',
        'width',
        'height',
        'viewbox',
        'transform',
        'fill',
        'stroke',
        'stroke-width',
        'stroke-dasharray',
        'stroke-linecap',
        'stroke-linejoin',
        'opacity',
        'fill-opacity',
        'stroke-opacity',
        'font-size',
        'font-family',
        'font-weight',
        'font-style',
        'text-anchor',
        'text-decoration',
        'letter-spacing',
        'word-spacing',
        'dominant-baseline',
        'alignment-baseline',
        'id',
        'class',
        'dx',
        'dy',
        'rotate',
        'gradientunits',
        'gradienttransform',
        'spreadmethod',
        'offset',
        'stop-color',
        'stop-opacity',
        'patternunits',
        'patterntransform',
        'markerwidth',
        'markerheight',
        'markerunits',
        'orient',
        'refx',
        'refy',
        'preserveaspectratio',
        'xmlns',
        'xmlns:xlink',
        'version',
        'fill-rule',
        'clip-rule',
        'clip-path',
        'mask',
        'filter',
        'color',
        'display',
        'visibility',
        'overflow',
        'cursor',
        'pointer-events',
        'shape-rendering',
        'image-rendering',
        'color-interpolation',
        'color-interpolation-filters',
        'flood-color',
        'flood-opacity',
        'lighting-color',
        'attributename',
        'attributetype',
        'begin',
        'dur',
        'end',
        'repeatcount',
        'values',
        'keytimes',
        'calcmode',
        'from',
        'to',
        'type',
        'in',
        'in2',
        'result',
        'stddeviation',
        'mode',
        'operator',
        'k1',
        'k2',
        'k3',
        'k4',
    ];

    /**
     * Elements that must be removed entirely (not just stripped).
     *
     * @var list<string>
     */
    private const array BLOCKED_ELEMENTS = [
        'script',
        'foreignobject',
        'iframe',
        'embed',
        'object',
    ];

    /**
     * Sanitize SVG content by removing all disallowed elements and attributes.
     *
     * @param string $svgContent Raw SVG content
     *
     * @return string Sanitized SVG content
     *
     * @throws CmsException If the SVG cannot be parsed
     */
    public function sanitize(string $svgContent): string
    {
        if (trim($svgContent) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $success = $dom->loadXML($svgContent, LIBXML_NONET | LIBXML_NOERROR);

        if (!$success || $dom->documentElement === null) {
            throw CmsException::unsafeSvgContent();
        }

        // Walk the tree depth-first, collecting nodes to remove
        $this->walkTree($dom->documentElement);

        // Remove processing instructions at root level (e.g., xml-stylesheet PIs)
        foreach (iterator_to_array($dom->childNodes) as $child) {
            if ($child instanceof DOMProcessingInstruction) {
                $dom->removeChild($child);
            }
        }

        // Re-serialize to clean SVG
        $result = $dom->saveXML($dom->documentElement);

        if ($result === false) {
            throw CmsException::unsafeSvgContent();
        }

        return $result;
    }

    /**
     * Depth-first tree walk: remove disallowed elements and strip disallowed attributes.
     */
    private function walkTree(DOMNode $node): void
    {
        if (!$node instanceof DOMElement) {
            return;
        }

        // Process children first (depth-first), collecting in reverse to avoid index shifting
        $children = [];

        foreach (iterator_to_array($node->childNodes) as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                $tagName = strtolower($child->localName ?? $child->nodeName);

                // Remove blocked elements entirely
                if (in_array($tagName, self::BLOCKED_ELEMENTS, true)) {
                    $node->removeChild($child);

                    continue;
                }

                // Remove disallowed elements entirely
                if (!in_array($tagName, self::ALLOWED_ELEMENTS, true)) {
                    $node->removeChild($child);

                    continue;
                }

                // Recurse into allowed elements
                $this->walkTree($child);

                // Strip disallowed attributes after recursion
                $this->filterAttributes($child);

                // Validate use/image href attributes
                $this->validateHrefAttributes($child, $node);
            }
        }
    }

    /**
     * Strip disallowed attributes from an element.
     */
    private function filterAttributes(DOMElement $element): void
    {
        $toRemove = [];
        $attributes = $element->attributes;

        if ($attributes === null) {
            return;
        }

        /** @var DOMAttr $attr */
        foreach (iterator_to_array($attributes) as $attr) {
            $attrNameLower = strtolower($attr->name);

            // Remove all on* event handlers
            if (str_starts_with($attrNameLower, 'on')) {
                $toRemove[] = $attr->name;

                continue;
            }

            // Remove style attributes containing expression() or dangerous url()
            if ($attrNameLower === 'style') {
                $toRemove[] = $attr->name;

                continue;
            }

            // Check against allowlist
            if (!in_array($attrNameLower, self::ALLOWED_ATTRIBUTES, true)) {
                // Allow href and xlink:href only for validated elements
                if ($attrNameLower !== 'href' && $attrNameLower !== 'xlink:href') {
                    $toRemove[] = $attr->name;
                }
            }
        }

        foreach ($toRemove as $attrName) {
            $element->removeAttribute($attrName);
        }
    }

    /**
     * Validate href and xlink:href attributes on use and image elements.
     */
    private function validateHrefAttributes(DOMElement $element, DOMNode $parent): void
    {
        $tagName = strtolower($element->localName ?? $element->nodeName);
        $hrefAttrs = ['href', 'xlink:href'];

        foreach ($hrefAttrs as $attrName) {
            if (!$element->hasAttribute($attrName)) {
                continue;
            }

            $href = $element->getAttribute($attrName);

            // Strip ALL whitespace and control characters to defeat obfuscation
            // attacks like "java\nscript:" or "java\x09script:"
            $hrefLower = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $href));

            // Block javascript: and vbscript: schemes
            if (str_starts_with($hrefLower, 'javascript:') || str_starts_with($hrefLower, 'vbscript:')) {
                $element->removeAttribute($attrName);

                continue;
            }

            // Block data: URIs (except safe image data URIs on image elements)
            if (str_starts_with($hrefLower, 'data:')) {
                if ($tagName !== 'image' || !$this->isSafeImageDataUri($hrefLower)) {
                    $element->removeAttribute($attrName);

                    continue;
                }
            }

            // Block external hrefs on use elements
            if ($tagName === 'use') {
                if (
                    str_starts_with($hrefLower, 'http:')
                    || str_starts_with($hrefLower, 'https:')
                    || str_starts_with($hrefLower, '://')
                ) {
                    $parent->removeChild($element);

                    return;
                }
            }

            // Block external hrefs on image elements
            if ($tagName === 'image') {
                if (
                    str_starts_with($hrefLower, 'http:')
                    || str_starts_with($hrefLower, 'https:')
                    || str_starts_with($hrefLower, '://')
                ) {
                    $element->removeAttribute($attrName);
                }
            }
        }
    }

    /**
     * Check if a data URI is a safe image type.
     */
    private function isSafeImageDataUri(string $uri): bool
    {
        return (bool) preg_match('#^data:image/(png|jpeg|gif|webp);base64,[A-Za-z0-9+/=]+\z#', $uri);
    }
}
