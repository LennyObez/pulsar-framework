<?php

declare(strict_types=1);

namespace Pulsar\Security\Dlp;

use Pulsar\Api\Api;
use Pulsar\Support\Html\Html5Parser;

/**
 * HTML-aware DLP scanner using PHP 8.4's native HTML5 parser.
 *
 * Unlike regex-on-raw-HTML, this extracts visible text content first,
 * avoiding false positives from HTML attributes, CSS classes, or script
 * content. Scans only what users actually see.
 *
 * Also scans specified HTML attributes (href, src, alt, title) separately
 * to catch sensitive data leaked into URLs or metadata.
 */
#[Api(since: '1.0.0')]
final readonly class HtmlAwareDlpScanner
{
    /** @var list<string> */
    private const array SCANNABLE_ATTRIBUTES = ['href', 'src', 'alt', 'title', 'value', 'placeholder'];

    public function __construct(
        private SensitivePatternRegistry $registry,
    ) {}

    /**
     * Scan HTML content for sensitive data using DOM-aware text extraction.
     *
     * Parses the HTML with the spec-compliant HTML5 parser, extracts
     * visible text and attribute values, then runs DLP pattern matching
     * against the extracted content (not the raw HTML).
     */
    public function scan(string $html): DlpScanResult
    {
        if ($html === '') {
            return DlpScanResult::clean($html);
        }

        // Extract visible text (excludes script, style, noscript, template)
        $visibleText = Html5Parser::extractText($html);

        // Scan visible text
        $textResult = $this->registry->scan($visibleText);

        // Scan attribute values for leaked data
        $attrContent = $this->extractScannableAttributes($html);
        $attrResult = $attrContent !== '' ? $this->registry->scan($attrContent) : DlpScanResult::clean('');

        // Merge results
        return $this->mergeResults($html, $textResult, $attrResult);
    }

    /**
     * Extract values from scannable HTML attributes.
     */
    private function extractScannableAttributes(string $html): string
    {
        $values = [];

        foreach (self::SCANNABLE_ATTRIBUTES as $attr) {
            $selectorMap = match ($attr) {
                'href' => 'a',
                'src' => 'img, script, iframe, video, audio, source',
                'value' => 'input',
                'placeholder' => 'input, textarea',
                default => '*',
            };

            $extracted = Html5Parser::extractAttributes($html, "[$attr]", $attr);
            $values = [...$values, ...$extracted];
        }

        return implode("\n", $values);
    }

    /**
     * Merge text and attribute scan results.
     */
    private function mergeResults(string $originalHtml, DlpScanResult $textResult, DlpScanResult $attrResult): DlpScanResult
    {
        if (!$textResult->detected && !$attrResult->detected) {
            return DlpScanResult::clean($originalHtml);
        }

        $allMatches = [...$textResult->matches, ...$attrResult->matches];
        $action = $textResult->detected ? $textResult->actionTaken : $attrResult->actionTaken;

        return new DlpScanResult(
            detected: true,
            actionTaken: $action,
            matches: $allMatches,
            redactedContent: $originalHtml,
        );
    }
}
