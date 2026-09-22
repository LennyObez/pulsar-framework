<?php

declare(strict_types=1);

namespace Pulsar\Security\Dlp;

use Pulsar\Api\Api;
use Pulsar\Support\Html\Html5Parser;

use function implode;

/**
 * HTML-aware DLP scanner using PHP 8.4's native HTML5 parser.
 *
 * Unlike regex-on-raw-HTML, this extracts visible text content first,
 * avoiding false positives from HTML attributes, CSS classes, or script
 * content. Scans only what users actually see.
 *
 * Also scans specified HTML attributes (href, src, alt, title) separately
 * to catch sensitive data leaked into URLs or metadata.
 * @api
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
            $extracted = Html5Parser::extractAttributes($html, "[$attr]", $attr);
            $values = [...$values, ...$extracted];
        }

        return implode("\n", $values);
    }

    /**
     * Merge text and attribute scan results.
     *
     * The merged status is the less conclusive of the two. Reporting the pair as
     * {@see DlpScanStatus::Completed} because neither half detected anything
     * would be the exact laundering {@see DlpScanStatus} exists to stop: with DLP
     * switched off both halves examine nothing, and the merge would have turned
     * two "did not look" answers into one "looked and found nothing".
     */
    private function mergeResults(string $originalHtml, DlpScanResult $textResult, DlpScanResult $attrResult): DlpScanResult
    {
        $status = self::worseOf($textResult->status, $attrResult->status);

        if (!$textResult->detected && !$attrResult->detected) {
            return new DlpScanResult(
                detected: false,
                actionTaken: DlpAction::Alert,
                matches: [],
                redactedContent: $originalHtml,
                status: $status,
            );
        }

        $allMatches = [...$textResult->matches, ...$attrResult->matches];
        $action = $textResult->detected ? $textResult->actionTaken : $attrResult->actionTaken;

        return new DlpScanResult(
            detected: true,
            actionTaken: $action,
            matches: $allMatches,
            redactedContent: $originalHtml,
            status: $status,
        );
    }

    /**
     * The status that concedes the most, so that one half's ignorance is not
     * covered by the other half's confidence.
     */
    private static function worseOf(DlpScanStatus $left, DlpScanStatus $right): DlpScanStatus
    {
        if ($left === DlpScanStatus::Failed || $right === DlpScanStatus::Failed) {
            return DlpScanStatus::Failed;
        }

        if ($left === DlpScanStatus::Disabled || $right === DlpScanStatus::Disabled) {
            return DlpScanStatus::Disabled;
        }

        return DlpScanStatus::Completed;
    }
}
