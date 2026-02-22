<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Validator;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

use function count;
use function in_array;

/**
 * Validates WCAG 1.3.1 and 4.1.2 for form accessibility.
 *
 * Checks:
 * - Form inputs have associated labels (via for/id or wrapping label)
 * - Radio/checkbox groups use fieldset/legend
 * - Hidden and submit/button inputs are skipped
 */
final readonly class FormLabelValidator implements ValidatorInterface
{
    /** Input types that do not require labels. */
    private const array SKIP_TYPES = ['hidden', 'submit', 'button', 'image', 'reset'];

    public function validate(string $html): array
    {
        $dom = $this->loadHtml($html);

        if ($dom === null) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $violations = [];

        $this->checkInputLabels($xpath, $violations);
        $this->checkRadioCheckboxGroups($xpath, $violations);

        return $violations;
    }

    /**
     * @param list<AccessibilityViolation> $violations
     */
    private function checkInputLabels(DOMXPath $xpath, array &$violations): void
    {
        $inputs = $xpath->query('//input|//select|//textarea');

        if ($inputs === false) {
            return;
        }

        foreach ($inputs as $input) {
            if (!$input instanceof DOMElement) {
                continue;
            }

            $type = strtolower($input->getAttribute('type') ?: 'text');

            if (in_array($type, self::SKIP_TYPES, true)) {
                continue;
            }

            if ($this->hasAriaLabel($input)) {
                continue;
            }

            if ($this->hasAssociatedLabel($input, $xpath)) {
                continue;
            }

            if ($this->isWrappedByLabel($input)) {
                continue;
            }

            $snippet = $this->getOuterHtml($input);
            $tagName = $input->nodeName;

            $violations[] = new AccessibilityViolation(
                rule: 'missing-form-label',
                severity: Severity::Error,
                element: $snippet,
                message: "<{$tagName}> element has no associated <label>, aria-label, or aria-labelledby attribute.",
                wcagCriterion: '4.1.2',
                line: $input->getLineNo(),
            );
        }
    }

    /**
     * @param list<AccessibilityViolation> $violations
     */
    private function checkRadioCheckboxGroups(DOMXPath $xpath, array &$violations): void
    {
        $groupTypes = ['radio', 'checkbox'];

        foreach ($groupTypes as $type) {
            $inputs = $xpath->query("//input[@type='{$type}']");

            if ($inputs === false || $inputs->length < 2) {
                continue;
            }

            // Group by name attribute
            $groups = [];

            foreach ($inputs as $input) {
                if (!$input instanceof DOMElement) {
                    continue;
                }

                $name = $input->getAttribute('name');

                if ($name === '') {
                    continue;
                }

                $groups[$name][] = $input;
            }

            foreach ($groups as $name => $groupInputs) {
                if (count($groupInputs) < 2) {
                    continue;
                }

                $firstInput = $groupInputs[0];

                if ($this->isInsideFieldset($firstInput)) {
                    continue;
                }

                if ($firstInput->getAttribute('role') === 'group' || $this->hasGroupRole($firstInput, $xpath)) {
                    continue;
                }

                $snippet = $this->getOuterHtml($firstInput);

                $violations[] = new AccessibilityViolation(
                    rule: 'missing-fieldset',
                    severity: Severity::Warning,
                    element: $snippet,
                    message: "Group of {$type} inputs with name \"{$name}\" should be wrapped in a <fieldset> with a <legend>.",
                    wcagCriterion: '1.3.1',
                    line: $firstInput->getLineNo(),
                );
            }
        }
    }

    private function hasAriaLabel(DOMElement $element): bool
    {
        return $element->hasAttribute('aria-label')
            || $element->hasAttribute('aria-labelledby')
            || $element->hasAttribute('title');
    }

    private function hasAssociatedLabel(DOMElement $input, DOMXPath $xpath): bool
    {
        $id = $input->getAttribute('id');

        if ($id === '') {
            return false;
        }

        $labels = $xpath->query("//label[@for='{$id}']");

        return $labels !== false && $labels->length > 0;
    }

    private function isWrappedByLabel(DOMElement $element): bool
    {
        $parent = $element->parentNode;

        while ($parent !== null) {
            if ($parent instanceof DOMElement && $parent->nodeName === 'label') {
                return true;
            }

            $parent = $parent->parentNode;
        }

        return false;
    }

    private function isInsideFieldset(DOMElement $element): bool
    {
        $parent = $element->parentNode;

        while ($parent !== null) {
            if ($parent instanceof DOMElement && $parent->nodeName === 'fieldset') {
                return true;
            }

            $parent = $parent->parentNode;
        }

        return false;
    }

    private function hasGroupRole(DOMElement $element, DOMXPath $xpath): bool
    {
        $parent = $element->parentNode;

        while ($parent !== null) {
            if ($parent instanceof DOMElement && $parent->getAttribute('role') === 'group') {
                return true;
            }

            $parent = $parent->parentNode;
        }

        return false;
    }

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
