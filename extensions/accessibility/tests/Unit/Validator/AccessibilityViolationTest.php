<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Validator;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Validator\AccessibilityViolation;
use Pulsar\Extension\Accessibility\Validator\Severity;

final class AccessibilityViolationTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $violation = new AccessibilityViolation(
            rule: 'img-alt',
            severity: Severity::Error,
            element: '<img src="photo.jpg">',
            message: 'Image element missing alt attribute',
            wcagCriterion: '1.1.1',
            line: 42,
        );

        self::assertSame('img-alt', $violation->rule);
        self::assertSame(Severity::Error, $violation->severity);
        self::assertSame('<img src="photo.jpg">', $violation->element);
        self::assertSame('Image element missing alt attribute', $violation->message);
        self::assertSame('1.1.1', $violation->wcagCriterion);
        self::assertSame(42, $violation->line);
    }

    #[Test]
    public function lineDefaultsToNull(): void
    {
        $violation = new AccessibilityViolation(
            rule: 'heading-order',
            severity: Severity::Warning,
            element: '<h3>',
            message: 'Heading level skipped',
            wcagCriterion: '1.3.1',
        );

        self::assertNull($violation->line);
    }
}
