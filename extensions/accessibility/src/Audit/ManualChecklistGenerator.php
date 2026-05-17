<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Audit;

use Pulsar\Api\Api;

/**
 * Generates a manual testing checklist for accessibility items
 * that automated tools cannot reliably detect.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ManualChecklistGenerator
{
    /**
     * @return list<ChecklistItem>
     */
    public function generate(): array
    {
        return [
            new ChecklistItem(
                id: 'manual-alt-text-quality',
                category: 'Images',
                description: 'Verify that alt text accurately describes the purpose and content of each image.',
                wcagCriterion: '1.1.1',
                wcagLevel: 'A',
                guidance: 'For informative images, alt text should convey the same information. '
                    . 'For decorative images, alt="" is correct. '
                    . 'For complex images (charts, diagrams), provide a detailed description nearby.',
            ),
            new ChecklistItem(
                id: 'manual-reading-order',
                category: 'Structure',
                description: 'Verify that the visual reading order matches the DOM order.',
                wcagCriterion: '1.3.2',
                wcagLevel: 'A',
                guidance: 'Tab through the page and verify the focus order is logical. '
                    . 'CSS layout (flexbox order, grid placement, absolute positioning) '
                    . 'should not cause a disconnect between visual and DOM order.',
            ),
            new ChecklistItem(
                id: 'manual-color-meaning',
                category: 'Color',
                description: 'Verify that color is not the sole means of conveying information.',
                wcagCriterion: '1.4.1',
                wcagLevel: 'A',
                guidance: 'Error states, required fields, and status indicators must have a non-color '
                    . 'indicator (icon, text, pattern) in addition to color.',
            ),
            new ChecklistItem(
                id: 'manual-reflow',
                category: 'Responsive',
                description: 'Verify content is usable at 400% zoom without horizontal scrolling.',
                wcagCriterion: '1.4.10',
                wcagLevel: 'AA',
                guidance: 'Set browser zoom to 400% on a 1280px viewport. '
                    . 'Content should reflow to a single column without loss of information or function. '
                    . 'No horizontal scrolling should be required except for data tables, images, and diagrams.',
            ),
            new ChecklistItem(
                id: 'manual-text-spacing',
                category: 'Typography',
                description: 'Verify content is readable with increased text spacing.',
                wcagCriterion: '1.4.12',
                wcagLevel: 'AA',
                guidance: 'Apply: line-height 1.5x, paragraph spacing 2x, letter spacing 0.12em, '
                    . 'word spacing 0.16em. Verify no content is clipped or overlapping.',
            ),
            new ChecklistItem(
                id: 'manual-keyboard-navigation',
                category: 'Keyboard',
                description: 'Verify all interactive elements are reachable and operable via keyboard.',
                wcagCriterion: '2.1.1',
                wcagLevel: 'A',
                guidance: 'Tab through every interactive element. Verify: visible focus indicator, '
                    . 'activation via Enter/Space, no keyboard traps (except intentional modals with Escape).',
            ),
            new ChecklistItem(
                id: 'manual-focus-visible',
                category: 'Keyboard',
                description: 'Verify focus indicators are clearly visible on all interactive elements.',
                wcagCriterion: '2.4.7',
                wcagLevel: 'AA',
                guidance: 'Every focusable element must have a visible focus indicator that meets '
                    . '3:1 contrast ratio against adjacent colors and is at least 2px.',
            ),
            new ChecklistItem(
                id: 'manual-cognitive-load',
                category: 'Cognitive',
                description: 'Verify forms and processes do not impose excessive cognitive load.',
                wcagCriterion: '3.3.2',
                wcagLevel: 'A',
                guidance: 'Form fields should have clear labels, helpful placeholder text, '
                    . 'and inline validation with specific error messages. Multi-step processes '
                    . 'should show progress and allow backtracking.',
            ),
            new ChecklistItem(
                id: 'manual-error-identification',
                category: 'Forms',
                description: 'Verify form errors are clearly identified and described in text.',
                wcagCriterion: '3.3.1',
                wcagLevel: 'A',
                guidance: 'When validation fails: the error is described in text (not just color), '
                    . 'the error is announced to screen readers via aria-live or aria-describedby, '
                    . 'and focus moves to the first error.',
            ),
            new ChecklistItem(
                id: 'manual-screen-reader',
                category: 'Screen Reader',
                description: 'Test full page navigation with a screen reader (NVDA, VoiceOver, or JAWS).',
                wcagCriterion: '4.1.2',
                wcagLevel: 'A',
                guidance: 'Navigate using screen reader commands. Verify: all content is announced, '
                    . 'landmarks provide navigation shortcuts, live regions announce dynamic changes, '
                    . 'custom widgets expose correct roles/states/values.',
            ),
            new ChecklistItem(
                id: 'manual-touch-target',
                category: 'Mobile',
                description: 'Verify touch targets are at least 44x44 CSS pixels.',
                wcagCriterion: '2.5.5',
                wcagLevel: 'AAA',
                guidance: 'Interactive elements on touch screens should be at least 44x44px. '
                    . 'Inline links in text are exempt, but buttons, form controls, and nav items are not.',
            ),
            new ChecklistItem(
                id: 'manual-media-captions',
                category: 'Media',
                description: 'Verify all video content has accurate captions and audio descriptions.',
                wcagCriterion: '1.2.2',
                wcagLevel: 'A',
                guidance: 'Pre-recorded video must have synchronized captions. '
                    . 'Captions must be accurate, synchronized, and include speaker identification '
                    . 'and relevant sound effects.',
            ),
        ];
    }

    /**
     * @return array<string, list<ChecklistItem>>
     */
    public function generateGrouped(): array
    {
        $grouped = [];

        foreach ($this->generate() as $item) {
            $grouped[$item->category][] = $item;
        }

        return $grouped;
    }
}
