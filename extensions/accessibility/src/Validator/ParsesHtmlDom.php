<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Validator;

use DOMDocument;
use DOMNode;

/**
 * Shared DOM loading and element rendering for accessibility validators.
 *
 * Every validator needs to parse an HTML fragment into a DOMDocument and
 * extract outer HTML snippets for violation reports. This trait eliminates
 * the identical loadHtml() / getOuterHtml() implementations.
 */
trait ParsesHtmlDom
{
    private function loadHtml(string $html): ?DOMDocument
    {
        $dom = new DOMDocument();
        $wrapped = '<div>' . $html . '</div>';
        libxml_use_internal_errors(true);
        $result = @$dom->loadHTML(
            '<?xml encoding="UTF-8"><body>' . $wrapped . '</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        if ($result === false) {
            return null;
        }

        return $dom;
    }

    private function getOuterHtml(DOMNode $node): string
    {
        /** @var DOMDocument $dom */
        $dom = $node->ownerDocument;

        return trim($dom->saveHTML($node) ?: '');
    }
}
