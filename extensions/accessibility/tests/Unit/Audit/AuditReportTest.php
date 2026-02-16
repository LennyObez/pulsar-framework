<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Audit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Audit\AuditReport;
use Pulsar\Extension\Accessibility\Validator\AccessibilityViolation;
use Pulsar\Extension\Accessibility\Validator\Severity;

final class AuditReportTest extends TestCase
{
    #[Test]
    public function errors_filters_by_severity(): void
    {
        $report = new AuditReport(
            violations: [
                new AccessibilityViolation('rule-a', Severity::Error, '<div>', 'Error msg', '1.1.1'),
                new AccessibilityViolation('rule-b', Severity::Warning, '<p>', 'Warning msg', '1.3.1'),
                new AccessibilityViolation('rule-c', Severity::Error, '<img>', 'Error msg 2', '1.1.1'),
            ],
            checksRun: 3,
            filesAudited: 1,
            timestamp: new DateTimeImmutable(),
        );

        $errors = $report->errors();

        self::assertCount(2, $errors);
        self::assertSame(Severity::Error, $errors[0]->severity);
        self::assertSame(Severity::Error, $errors[1]->severity);
    }

    #[Test]
    public function warnings_filters_by_severity(): void
    {
        $report = new AuditReport(
            violations: [
                new AccessibilityViolation('rule-a', Severity::Error, '<div>', 'Error msg', '1.1.1'),
                new AccessibilityViolation('rule-b', Severity::Warning, '<p>', 'Warning msg', '1.3.1'),
                new AccessibilityViolation('rule-c', Severity::Info, '<span>', 'Info msg', '2.1.1'),
            ],
            checksRun: 3,
            filesAudited: 1,
            timestamp: new DateTimeImmutable(),
        );

        $warnings = $report->warnings();

        self::assertCount(1, $warnings);
        self::assertSame(Severity::Warning, $warnings[0]->severity);
    }

    #[Test]
    public function summary_returns_correct_counts(): void
    {
        $report = new AuditReport(
            violations: [
                new AccessibilityViolation('rule-a', Severity::Error, '', 'msg', '1.1.1'),
                new AccessibilityViolation('rule-b', Severity::Warning, '', 'msg', '1.3.1'),
                new AccessibilityViolation('rule-c', Severity::Warning, '', 'msg', '1.3.1'),
                new AccessibilityViolation('rule-d', Severity::Info, '', 'msg', '2.1.1'),
            ],
            checksRun: 5,
            filesAudited: 2,
            timestamp: new DateTimeImmutable(),
        );

        $summary = $report->summary();

        self::assertSame(5, $summary->totalChecks);
        self::assertSame(1, $summary->errorCount);
        self::assertSame(2, $summary->warningCount);
        self::assertSame(1, $summary->infoCount);
        self::assertSame(4, $summary->totalViolations);
        self::assertSame(2, $summary->filesAudited);
    }

    #[Test]
    public function limitations_returns_non_empty_list(): void
    {
        $report = new AuditReport(
            violations: [],
            checksRun: 0,
            filesAudited: 0,
            timestamp: new DateTimeImmutable(),
        );

        $limitations = $report->limitations();

        self::assertNotEmpty($limitations);
        self::assertIsString($limitations[0]);
    }

    #[Test]
    public function to_array_includes_disclaimer(): void
    {
        $report = new AuditReport(
            violations: [],
            checksRun: 1,
            filesAudited: 0,
            timestamp: new DateTimeImmutable(),
        );

        $array = $report->toArray();

        self::assertArrayHasKey('disclaimer', $array);
        self::assertIsString($array['disclaimer']);
        self::assertStringContainsString('automated checks only', $array['disclaimer']);
    }

    #[Test]
    public function to_array_never_contains_wcag_compliant_text(): void
    {
        $report = new AuditReport(
            violations: [],
            checksRun: 10,
            filesAudited: 5,
            timestamp: new DateTimeImmutable(),
        );

        $json = json_encode($report->toArray());
        self::assertIsString($json);

        self::assertStringNotContainsString('WCAG compliant', $json);
        self::assertStringNotContainsString('wcag compliant', strtolower($json));
    }

    #[Test]
    public function has_errors_returns_true_when_errors_exist(): void
    {
        $report = new AuditReport(
            violations: [
                new AccessibilityViolation('rule-a', Severity::Error, '', 'msg', '1.1.1'),
            ],
            checksRun: 1,
            filesAudited: 0,
            timestamp: new DateTimeImmutable(),
        );

        self::assertTrue($report->hasErrors());
    }

    #[Test]
    public function has_errors_returns_false_when_no_errors(): void
    {
        $report = new AuditReport(
            violations: [
                new AccessibilityViolation('rule-a', Severity::Warning, '', 'msg', '1.3.1'),
            ],
            checksRun: 1,
            filesAudited: 0,
            timestamp: new DateTimeImmutable(),
        );

        self::assertFalse($report->hasErrors());
    }

    #[Test]
    public function to_array_includes_limitations(): void
    {
        $report = new AuditReport(
            violations: [],
            checksRun: 0,
            filesAudited: 0,
            timestamp: new DateTimeImmutable(),
        );

        $array = $report->toArray();

        self::assertArrayHasKey('limitations', $array);
        self::assertNotEmpty($array['limitations']);
    }

    #[Test]
    public function to_array_includes_violation_details(): void
    {
        $report = new AuditReport(
            violations: [
                new AccessibilityViolation('missing-alt', Severity::Error, '<img>', 'Missing alt', '1.1.1', 5),
            ],
            checksRun: 1,
            filesAudited: 1,
            timestamp: new DateTimeImmutable(),
        );

        $array = $report->toArray();

        /** @var list<array<string, mixed>> $violations */
        $violations = $array['violations'];
        self::assertCount(1, $violations);
        self::assertSame('missing-alt', $violations[0]['rule']);
        self::assertSame('error', $violations[0]['severity']);
        self::assertSame('1.1.1', $violations[0]['wcag_criterion']);
        self::assertSame(5, $violations[0]['line']);
    }
}
