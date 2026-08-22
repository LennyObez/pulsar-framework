<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Validator;

use DOMElement;
use DOMXPath;

use function in_array;

/**
 * Validates WCAG 1.1.1 (Non-text Content) for image alt text.
 *
 * Checks:
 * - Images have alt attributes
 * - Alt text is not suspiciously generic
 * - Decorative images (alt="") are accepted
 * - Images with role="presentation" or aria-hidden="true" are exempt
 */
final readonly class AltTextValidator implements ValidatorInterface
{
    use ParsesHtmlDom;
    /** Generic alt text values that indicate poor accessibility practice. */
    private const array GENERIC_ALT_VALUES = [
        'image',
        'photo',
        'picture',
        'icon',
        'logo',
        'graphic',
        'screenshot',
    ];

    public function validate(string $html): array
    {
        $dom = $this->loadHtml($html);

        if ($dom === null) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $images = $xpath->query('//img');

        if ($images === false || $images->length === 0) {
            return [];
        }

        $violations = [];

        foreach ($images as $image) {
            if (!$image instanceof DOMElement) {
                continue;
            }

            if ($this->isDecorativeOrHidden($image)) {
                continue;
            }

            $snippet = $this->getOuterHtml($image);
            $line = $image->getLineNo();

            if (!$image->hasAttribute('alt')) {
                $violations[] = new AccessibilityViolation(
                    rule: 'missing-alt',
                    severity: Severity::Error,
                    element: $snippet,
                    message: '<img> element is missing an alt attribute. Provide descriptive alt text or alt="" for decorative images.',
                    wcagCriterion: '1.1.1',
                    line: $line,
                );

                continue;
            }

            $altText = $image->getAttribute('alt');

            // alt="" is valid for decorative images: no violation
            if ($altText === '') {
                continue;
            }

            $normalizedAlt = strtolower(trim($altText));

            if (in_array($normalizedAlt, self::GENERIC_ALT_VALUES, true)) {
                $violations[] = new AccessibilityViolation(
                    rule: 'generic-alt-text',
                    severity: Severity::Warning,
                    element: $snippet,
                    message: "Alt text \"$altText\" is too generic. Provide a meaningful description of the image content.",
                    wcagCriterion: '1.1.1',
                    line: $line,
                );
            }
        }

        return $violations;
    }

    private function isDecorativeOrHidden(DOMElement $image): bool
    {
        return $image->getAttribute('role') === 'presentation'
            || $image->getAttribute('aria-hidden') === 'true';
    }

}
