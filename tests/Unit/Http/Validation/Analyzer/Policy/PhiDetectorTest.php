<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Analyzer\Policy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Analyzer\AnalyzerFinding;
use Pulsar\Http\Validation\Analyzer\FindingSeverity;
use Pulsar\Http\Validation\Analyzer\Policy\PhiDetector;

use function array_filter;
use function array_values;
use function count;
use function str_contains;

#[CoversClass(PhiDetector::class)]
final class PhiDetectorTest extends TestCase
{
    private PhiDetector $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new PhiDetector();
    }

    #[Test]
    public function detectsSsnPattern(): void
    {
        $findings = $this->analyzer->analyze('notes', '123-45-6789', []);

        self::assertCount(1, $findings);
        self::assertSame(FindingSeverity::Critical, $findings[0]->severity);
        self::assertSame(0.9, $findings[0]->confidence);
        self::assertStringContainsString('SSN', $findings[0]->pattern);
    }

    #[Test]
    public function detectsDateOfBirthPatternSlashFormat(): void
    {
        $findings = $this->analyzer->analyze('notes', '01/15/1990', []);

        $dateFindings = array_values(array_filter(
            $findings,
            static fn(AnalyzerFinding $f): bool => str_contains($f->pattern, 'Date'),
        ));

        self::assertNotEmpty($dateFindings);
        self::assertSame(FindingSeverity::Warning, $dateFindings[0]->severity);
        self::assertSame(0.6, $dateFindings[0]->confidence);
    }

    #[Test]
    public function detectsDateOfBirthPatternIsoFormat(): void
    {
        $findings = $this->analyzer->analyze('notes', '1990-01-15', []);

        $dateFindings = array_values(array_filter(
            $findings,
            static fn(AnalyzerFinding $f): bool => str_contains($f->pattern, 'Date'),
        ));

        self::assertNotEmpty($dateFindings);
        self::assertSame(FindingSeverity::Warning, $dateFindings[0]->severity);
    }

    #[Test]
    public function detectsPhoneNumberDashedFormat(): void
    {
        $findings = $this->analyzer->analyze('contact', '555-123-4567', []);

        $phoneFindings = array_values(array_filter(
            $findings,
            static fn(AnalyzerFinding $f): bool => str_contains($f->pattern, 'Phone'),
        ));

        self::assertNotEmpty($phoneFindings);
        self::assertSame(FindingSeverity::Warning, $phoneFindings[0]->severity);
        self::assertSame(0.7, $phoneFindings[0]->confidence);
    }

    #[Test]
    public function detectsPhoneNumberParenthesizedFormat(): void
    {
        $findings = $this->analyzer->analyze('phone', '(555) 123-4567', []);

        $phoneFindings = array_values(array_filter(
            $findings,
            static fn(AnalyzerFinding $f): bool => str_contains($f->pattern, 'Phone'),
        ));

        self::assertNotEmpty($phoneFindings);
    }

    #[Test]
    public function detectsEmailAddress(): void
    {
        $findings = $this->analyzer->analyze('notes', 'patient@hospital.com', []);

        $emailFindings = array_values(array_filter(
            $findings,
            static fn(AnalyzerFinding $f): bool => str_contains($f->pattern, 'Email'),
        ));

        self::assertNotEmpty($emailFindings);
        self::assertSame(FindingSeverity::Warning, $emailFindings[0]->severity);
        self::assertSame(0.7, $emailFindings[0]->confidence);
    }

    #[Test]
    public function detectsMrnLikeIdentifier(): void
    {
        $findings = $this->analyzer->analyze('identifier', 'MRN12345678', []);

        $mrnFindings = array_values(array_filter(
            $findings,
            static fn(AnalyzerFinding $f): bool => str_contains($f->pattern, 'MRN'),
        ));

        self::assertNotEmpty($mrnFindings);
        self::assertSame(FindingSeverity::Info, $mrnFindings[0]->severity);
        self::assertSame(0.5, $mrnFindings[0]->confidence);
    }

    #[Test]
    public function multiplePatternsSingleValue(): void
    {
        $findings = $this->analyzer->analyze(
            'notes',
            'SSN: 123-45-6789, email: user@example.com',
            [],
        );

        self::assertGreaterThanOrEqual(2, count($findings));
    }

    #[Test]
    public function plainTextReturnsEmpty(): void
    {
        $findings = $this->analyzer->analyze('notes', 'just some plain text', []);

        self::assertSame([], $findings);
    }

    #[Test]
    public function emptyStringReturnsEmpty(): void
    {
        self::assertSame([], $this->analyzer->analyze('notes', '', []));
    }

    #[Test]
    public function nonStringReturnsEmpty(): void
    {
        self::assertSame([], $this->analyzer->analyze('age', 25, []));
    }

    #[Test]
    public function nullReturnsEmpty(): void
    {
        self::assertSame([], $this->analyzer->analyze('notes', null, []));
    }

    #[Test]
    public function findingsPreserveFieldName(): void
    {
        $findings = $this->analyzer->analyze('patient_notes', '123-45-6789', []);

        self::assertNotEmpty($findings);
        self::assertSame('patient_notes', $findings[0]->field);
    }

    #[Test]
    public function findingsContainRecommendations(): void
    {
        $findings = $this->analyzer->analyze('notes', '123-45-6789', []);

        self::assertNotEmpty($findings);
        self::assertNotEmpty($findings[0]->recommendation);
    }
}
