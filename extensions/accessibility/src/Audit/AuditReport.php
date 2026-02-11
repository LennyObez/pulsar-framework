<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Audit;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Accessibility\Validator\AccessibilityViolation;
use Pulsar\Extension\Accessibility\Validator\Severity;

use function count;

/**
 * Aggregated results from an accessibility audit.
 *
 * Reports check results honestly — never claims "WCAG compliant".
 * Only reports "X automated checks passed, Y issues found, Z items require manual review".
 */
#[Api(since: '1.0.0')]
final readonly class AuditReport
{
    /** @var list<string> */
    private const array LIMITATIONS = [
        'Automated tools can only detect approximately 30-40% of WCAG 2.1 issues.',
        'Cognitive accessibility (reading level, cognitive load) requires human evaluation.',
        'Meaningful reading order and context-dependent content cannot be verified automatically.',
        'Whether alt text accurately describes image content requires human judgement.',
        'Color conveying meaning beyond contrast ratios requires manual review.',
        'Touch target sizing and spacing for mobile accessibility needs manual testing.',
        'Complex interaction patterns (drag-and-drop, gestures) need manual screen reader testing.',
        'Content reflow at 400% zoom requires visual verification.',
        'Audio/video captions and transcripts need human review for accuracy.',
    ];

    /**
     * @param list<AccessibilityViolation> $violations
     * @param int $checksRun
     * @param int $filesAudited
     */
    public function __construct(
        public array $violations,
        public int $checksRun,
        public int $filesAudited,
        public DateTimeImmutable $timestamp,
    ) {}

    /**
     * @return list<AccessibilityViolation>
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->violations,
            static fn(AccessibilityViolation $v): bool => $v->severity === Severity::Error,
        ));
    }

    /**
     * @return list<AccessibilityViolation>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->violations,
            static fn(AccessibilityViolation $v): bool => $v->severity === Severity::Warning,
        ));
    }

    /**
     * @return list<AccessibilityViolation>
     */
    public function infos(): array
    {
        return array_values(array_filter(
            $this->violations,
            static fn(AccessibilityViolation $v): bool => $v->severity === Severity::Info,
        ));
    }

    public function summary(): AuditSummary
    {
        return new AuditSummary(
            totalChecks: $this->checksRun,
            errorCount: count($this->errors()),
            warningCount: count($this->warnings()),
            infoCount: count($this->infos()),
            filesAudited: $this->filesAudited,
        );
    }

    public function hasErrors(): bool
    {
        return count($this->errors()) > 0;
    }

    /**
     * Explicit limitations of automated accessibility checking.
     *
     * @return list<string>
     */
    public function limitations(): array
    {
        return self::LIMITATIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary()->toArray(),
            'violations' => array_map(
                static fn(AccessibilityViolation $v): array => [
                    'rule' => $v->rule,
                    'severity' => $v->severity->value,
                    'element' => $v->element,
                    'message' => $v->message,
                    'wcag_criterion' => $v->wcagCriterion,
                    'line' => $v->line,
                ],
                $this->violations,
            ),
            'limitations' => self::LIMITATIONS,
            'timestamp' => $this->timestamp->format('c'),
            'disclaimer' => 'This report covers automated checks only. '
                . 'Automated tools detect approximately 30-40% of WCAG 2.1 issues. '
                . 'Manual testing is required for full compliance assessment.',
        ];
    }
}
