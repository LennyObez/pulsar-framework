<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Analyzer\Policy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Analyzer\FindingSeverity;
use Pulsar\Http\Validation\Analyzer\Policy\DataMinimizationAnalyzer;

#[CoversClass(DataMinimizationAnalyzer::class)]
final class DataMinimizationAnalyzerTest extends TestCase
{
    private DataMinimizationAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new DataMinimizationAnalyzer();
    }

    #[Test]
    public function detectsMaidenNameField(): void
    {
        $findings = $this->analyzer->analyze('maiden_name', 'Smith', []);

        self::assertCount(1, $findings);
        self::assertSame(FindingSeverity::Critical, $findings[0]->severity);
        self::assertSame(0.9, $findings[0]->confidence);
        self::assertStringContainsString('Maiden name', $findings[0]->pattern);
    }

    #[Test]
    public function detectsMothersMaidenField(): void
    {
        $findings = $this->analyzer->analyze('mothers_maiden', 'Johnson', []);

        self::assertNotEmpty($findings);
        self::assertSame(FindingSeverity::Critical, $findings[0]->severity);
    }

    #[Test]
    public function detectsFullSsnField(): void
    {
        $findings = $this->analyzer->analyze('full_ssn', '123456789', []);

        self::assertCount(1, $findings);
        self::assertSame(FindingSeverity::Critical, $findings[0]->severity);
        self::assertSame(0.95, $findings[0]->confidence);
    }

    #[Test]
    public function detectsSocialSecurityField(): void
    {
        $findings = $this->analyzer->analyze('social_security', '123456789', []);

        self::assertNotEmpty($findings);
        self::assertSame(FindingSeverity::Critical, $findings[0]->severity);
    }

    #[Test]
    public function detectsDriversLicenseField(): void
    {
        $findings = $this->analyzer->analyze('drivers_license_number', 'D1234567', []);

        self::assertCount(1, $findings);
        self::assertSame(FindingSeverity::Warning, $findings[0]->severity);
        self::assertSame(0.85, $findings[0]->confidence);
    }

    #[Test]
    public function detectsPassportField(): void
    {
        $findings = $this->analyzer->analyze('passport_number', 'AB1234567', []);

        self::assertNotEmpty($findings);
        self::assertSame(FindingSeverity::Warning, $findings[0]->severity);
    }

    #[Test]
    public function detectsBankAccountField(): void
    {
        $findings = $this->analyzer->analyze('bank_account', '1234567890', []);

        self::assertNotEmpty($findings);
        self::assertSame(FindingSeverity::Warning, $findings[0]->severity);
        self::assertSame(0.8, $findings[0]->confidence);
    }

    #[Test]
    public function detectsBiometricField(): void
    {
        $findings = $this->analyzer->analyze('fingerprint', 'data', []);

        self::assertCount(1, $findings);
        self::assertSame(FindingSeverity::Critical, $findings[0]->severity);
        self::assertSame(0.9, $findings[0]->confidence);
    }

    #[Test]
    public function detectsSensitiveDemographicField(): void
    {
        $findings = $this->analyzer->analyze('ethnicity', 'value', []);

        self::assertNotEmpty($findings);
        self::assertSame(FindingSeverity::Warning, $findings[0]->severity);
        self::assertSame(0.8, $findings[0]->confidence);
    }

    #[Test]
    public function normalFieldReturnsEmpty(): void
    {
        self::assertSame([], $this->analyzer->analyze('email', 'user@example.com', []));
    }

    #[Test]
    public function commonFieldNamesReturnEmpty(): void
    {
        self::assertSame([], $this->analyzer->analyze('first_name', 'John', []));
        self::assertSame([], $this->analyzer->analyze('last_name', 'Doe', []));
        self::assertSame([], $this->analyzer->analyze('address', '123 Main St', []));
    }

    #[Test]
    public function caseInsensitiveMatching(): void
    {
        $findings = $this->analyzer->analyze('MAIDEN_NAME', 'Smith', []);

        self::assertNotEmpty($findings);
        self::assertSame(FindingSeverity::Critical, $findings[0]->severity);
    }

    #[Test]
    public function findingsPreserveOriginalFieldName(): void
    {
        $findings = $this->analyzer->analyze('Maiden_Name', 'Smith', []);

        self::assertNotEmpty($findings);
        self::assertSame('Maiden_Name', $findings[0]->field);
    }

    #[Test]
    public function findingsContainRecommendations(): void
    {
        $findings = $this->analyzer->analyze('maiden_name', 'Smith', []);

        self::assertNotEmpty($findings);
        self::assertStringContainsString('data minimization', $findings[0]->recommendation);
    }
}
