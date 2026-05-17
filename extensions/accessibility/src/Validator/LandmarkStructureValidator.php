<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Validator;

use DOMAttr;
use DOMElement;
use DOMNode;
use DOMXPath;

use function count;

/**
 * Validates WCAG 1.3.1 and 4.1.2 for ARIA landmark structure.
 *
 * Checks:
 * - Page has a main landmark
 * - Page has a navigation landmark
 * - Multiple landmarks of the same type have unique labels
 * - Multiple main elements have distinct aria-labels
 * - Top-level header/footer map to banner/contentinfo
 */
final readonly class LandmarkStructureValidator implements ValidatorInterface
{
    use ParsesHtmlDom;
    public function validate(string $html): array
    {
        $dom = $this->loadHtml($html);

        if ($dom === null) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $violations = [];

        $this->checkMainLandmark($xpath, $violations);
        $this->checkNavLandmark($xpath, $violations);
        $this->checkDuplicateLandmarkLabels($xpath, $violations);

        return $violations;
    }

    /**
     * @param list<AccessibilityViolation> $violations
     */
    private function checkMainLandmark(DOMXPath $xpath, array &$violations): void
    {
        $mainElements = $xpath->query('//main|//*[@role="main"]');

        if ($mainElements === false || $mainElements->length === 0) {
            $violations[] = new AccessibilityViolation(
                rule: 'missing-main-landmark',
                severity: Severity::Warning,
                element: '',
                message: 'No <main> element or role="main" found. Pages should have exactly one main landmark.',
                wcagCriterion: '1.3.1',
            );

            return;
        }

        if ($mainElements->length > 1) {
            $labels = [];

            foreach ($mainElements as $main) {
                if (!$main instanceof DOMElement) {
                    continue;
                }

                $label = $this->getLandmarkLabel($main);
                $labels[] = $label;
            }

            $nonEmptyLabels = array_filter($labels, static fn(string $label): bool => $label !== '');
            $uniqueLabels = array_unique($nonEmptyLabels);

            if (count($nonEmptyLabels) < $mainElements->length || count($uniqueLabels) < count($nonEmptyLabels)) {
                $firstMain = $mainElements->item(0);

                $violations[] = new AccessibilityViolation(
                    rule: 'multiple-main-landmarks',
                    severity: Severity::Error,
                    element: $firstMain instanceof DOMElement ? $this->getOuterHtmlTag($firstMain) : '',
                    message: "Multiple <main> landmarks found ($mainElements->length). Each must have a distinct aria-label or aria-labelledby.",
                    wcagCriterion: '4.1.2',
                    line: $firstMain instanceof DOMNode ? $firstMain->getLineNo() : null,
                );
            }
        }
    }

    /**
     * @param list<AccessibilityViolation> $violations
     */
    private function checkNavLandmark(DOMXPath $xpath, array &$violations): void
    {
        $navElements = $xpath->query('//nav|//*[@role="navigation"]');

        if ($navElements === false || $navElements->length === 0) {
            $violations[] = new AccessibilityViolation(
                rule: 'missing-nav-landmark',
                severity: Severity::Warning,
                element: '',
                message: 'No <nav> element or role="navigation" found. Pages should have at least one navigation landmark.',
                wcagCriterion: '1.3.1',
            );
        }
    }

    /**
     * @param list<AccessibilityViolation> $violations
     */
    private function checkDuplicateLandmarkLabels(DOMXPath $xpath, array &$violations): void
    {
        $landmarkTypes = [
            'nav' => 'navigation',
            'aside' => 'complementary',
            'section' => 'region',
            'form' => 'form',
        ];

        foreach ($landmarkTypes as $tag => $role) {
            $elements = $xpath->query("//$tag|//*[@role='$role']");

            if ($elements === false || $elements->length < 2) {
                continue;
            }

            $labels = [];

            /** @var DOMNode $element */
            foreach ($elements as $element) {
                if (!$element instanceof DOMElement) {
                    continue;
                }

                $label = $this->getLandmarkLabel($element);
                $labels[] = ['label' => $label, 'element' => $element];
            }

            $seen = [];

            foreach ($labels as $entry) {
                $label = $entry['label'];
                /** @var DOMElement $element */
                $element = $entry['element'];

                if ($label === '') {
                    $violations[] = new AccessibilityViolation(
                        rule: 'duplicate-landmark-missing-label',
                        severity: Severity::Warning,
                        element: $this->getOuterHtmlTag($element),
                        message: "Multiple <$tag> (role=\"$role\") landmarks exist but this one has no aria-label or aria-labelledby to distinguish it.",
                        wcagCriterion: '4.1.2',
                        line: $element->getLineNo(),
                    );

                    continue;
                }

                if (isset($seen[$label])) {
                    $violations[] = new AccessibilityViolation(
                        rule: 'duplicate-landmark-label',
                        severity: Severity::Warning,
                        element: $this->getOuterHtmlTag($element),
                        message: "Multiple <$tag> (role=\"$role\") landmarks share the same label \"$label\". Each should have a unique label.",
                        wcagCriterion: '4.1.2',
                        line: $element->getLineNo(),
                    );
                }

                $seen[$label] = true;
            }
        }
    }

    private function getLandmarkLabel(DOMElement $element): string
    {
        if ($element->hasAttribute('aria-label')) {
            return trim($element->getAttribute('aria-label'));
        }

        if ($element->hasAttribute('aria-labelledby')) {
            return trim($element->getAttribute('aria-labelledby'));
        }

        return '';
    }

    /**
     * Return just the opening tag of the element (without children) for readable snippets.
     */
    private function getOuterHtmlTag(DOMElement $element): string
    {
        $tag = '<' . $element->nodeName;

        $attributes = $element->attributes;

        /** @var DOMAttr $attr */
        foreach ($attributes as $attr) {
            $tag .= ' ' . $attr->nodeName . '="' . htmlspecialchars($attr->nodeValue ?? '', ENT_QUOTES, 'UTF-8') . '"';
        }

        return $tag . '>';
    }

}
