<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Analyzer;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Analyzer\AnalyzerFinding;
use Pulsar\Http\Validation\Analyzer\FindingSeverity;

#[CoversClass(AnalyzerFinding::class)]
final class AnalyzerFindingTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $finding = new AnalyzerFinding(
            severity: FindingSeverity::Warning,
            confidence: 0.85,
            field: 'password',
            pattern: 'common_password_pattern',
            recommendation: 'Use a stronger password.',
        );

        self::assertSame(FindingSeverity::Warning, $finding->severity);
        self::assertSame(0.85, $finding->confidence);
        self::assertSame('password', $finding->field);
        self::assertSame('common_password_pattern', $finding->pattern);
        self::assertSame('Use a stronger password.', $finding->recommendation);
    }

    #[Test]
    public function acceptsZeroConfidence(): void
    {
        $finding = new AnalyzerFinding(
            severity: FindingSeverity::Info,
            confidence: 0.0,
            field: 'name',
            pattern: 'test',
            recommendation: 'Consider review.',
        );

        self::assertSame(0.0, $finding->confidence);
    }

    #[Test]
    public function acceptsFullConfidence(): void
    {
        $finding = new AnalyzerFinding(
            severity: FindingSeverity::Critical,
            confidence: 1.0,
            field: 'ssn',
            pattern: 'ssn_format',
            recommendation: 'Mask this field.',
        );

        self::assertSame(1.0, $finding->confidence);
    }

    #[Test]
    public function rejectsConfidenceBelowZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Confidence must be between 0.0 and 1.0');

        new AnalyzerFinding(
            severity: FindingSeverity::Info,
            confidence: -0.1,
            field: 'test',
            pattern: 'test',
            recommendation: 'test',
        );
    }

    #[Test]
    public function rejectsConfidenceAboveOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Confidence must be between 0.0 and 1.0');

        new AnalyzerFinding(
            severity: FindingSeverity::Info,
            confidence: 1.1,
            field: 'test',
            pattern: 'test',
            recommendation: 'test',
        );
    }
}
