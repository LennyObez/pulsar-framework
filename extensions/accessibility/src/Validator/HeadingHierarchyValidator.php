<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Validator;

use DOMNode;
use DOMXPath;

/**
 * Validates WCAG 1.3.1 (Info and Relationships) for heading structure.
 *
 * Checks:
 * - Heading levels are sequential (no skipping, e.g., h1 -> h3 without h2)
 * - No more than one h1 element per page
 * - Heading nesting follows logical order
 */
final readonly class HeadingHierarchyValidator implements ValidatorInterface
{
    use ParsesHtmlDom;
    public function validate(string $html): array
    {
        $dom = $this->loadHtml($html);

        if ($dom === null) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $headings = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');

        if ($headings === false || $headings->length === 0) {
            return [];
        }

        $violations = [];
        $h1Count = 0;
        $previousLevel = 0;

        /** @var DOMNode $heading */
        foreach ($headings as $heading) {
            $level = (int) substr($heading->nodeName, 1);
            $snippet = $this->getOuterHtml($heading);
            $line = $heading->getLineNo();

            if ($level === 1) {
                $h1Count++;

                if ($h1Count > 1) {
                    $violations[] = new AccessibilityViolation(
                        rule: 'multiple-h1',
                        severity: Severity::Warning,
                        element: $snippet,
                        message: "Multiple <h1> elements found ($h1Count total). Pages should typically have a single <h1> element.",
                        wcagCriterion: '1.3.1',
                        line: $line,
                    );
                }
            }

            if ($previousLevel > 0 && $level > $previousLevel + 1) {
                $violations[] = new AccessibilityViolation(
                    rule: 'heading-level-skip',
                    severity: Severity::Error,
                    element: $snippet,
                    message: "Heading level skipped: <h$level> follows <h$previousLevel>. Expected <h" . ($previousLevel + 1) . '> or lower.',
                    wcagCriterion: '1.3.1',
                    line: $line,
                );
            }

            $previousLevel = $level;
        }

        return $violations;
    }

}
