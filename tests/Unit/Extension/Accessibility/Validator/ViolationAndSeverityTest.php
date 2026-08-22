<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Accessibility\Validator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Validator\AccessibilityViolation;
use Pulsar\Extension\Accessibility\Validator\Severity;

#[CoversClass(AccessibilityViolation::class)]
final class ViolationAndSeverityTest extends TestCase
{
    #[Test]
    public function severityValues(): void
    {
        self::assertSame('error', Severity::Error->value);
        self::assertSame('warning', Severity::Warning->value);
        self::assertSame('info', Severity::Info->value);
        self::assertCount(3, Severity::cases());
    }

    #[Test]
    public function violationConstructionWithAllFields(): void
    {
        $violation = new AccessibilityViolation(
            rule: 'alt-text-missing',
            severity: Severity::Error,
            element: '<img src="photo.jpg">',
            message: 'Image is missing alt attribute',
            wcagCriterion: '1.1.1',
            line: 42,
        );

        self::assertSame('alt-text-missing', $violation->rule);
        self::assertSame(Severity::Error, $violation->severity);
        self::assertSame('<img src="photo.jpg">', $violation->element);
        self::assertSame('Image is missing alt attribute', $violation->message);
        self::assertSame('1.1.1', $violation->wcagCriterion);
        self::assertSame(42, $violation->line);
    }

    #[Test]
    public function violationWithNullLine(): void
    {
        $violation = new AccessibilityViolation(
            rule: 'heading-skip',
            severity: Severity::Warning,
            element: '<h3>',
            message: 'Skipped heading level',
            wcagCriterion: '1.3.1',
        );

        self::assertNull($violation->line);
    }
}
