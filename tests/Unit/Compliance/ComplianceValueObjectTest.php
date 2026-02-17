<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\VerificationResult;

#[CoversClass(ComplianceFramework::class)]
#[CoversClass(Control::class)]
#[CoversClass(ControlStatus::class)]
#[CoversClass(VerificationResult::class)]
final class ComplianceValueObjectTest extends TestCase
{
    // ── ControlStatus ─────────────────────────────────────────────────

    #[Test]
    public function controlStatusHasFourCases(): void
    {
        self::assertCount(4, ControlStatus::cases());
    }

    #[Test]
    #[DataProvider('controlStatusProvider')]
    public function controlStatusBackedValues(ControlStatus $status, string $expected): void
    {
        self::assertSame($expected, $status->value);
    }

    /**
     * @return iterable<string, array{ControlStatus, string}>
     */
    public static function controlStatusProvider(): iterable
    {
        yield 'Implemented' => [ControlStatus::Implemented, 'implemented'];
        yield 'Partial' => [ControlStatus::Partial, 'partial'];
        yield 'Planned' => [ControlStatus::Planned, 'planned'];
        yield 'NotApplicable' => [ControlStatus::NotApplicable, 'not_applicable'];
    }

    // ── Control ───────────────────────────────────────────────────────

    #[Test]
    public function controlConstructionWithDefaults(): void
    {
        $control = new Control(
            id: 'CC6.1',
            framework: 'soc2',
            title: 'Logical Access',
            description: 'Restricts logical access to relevant systems',
            status: ControlStatus::Implemented,
        );

        self::assertSame('CC6.1', $control->id);
        self::assertSame('soc2', $control->framework);
        self::assertSame('Logical Access', $control->title);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertSame([], $control->frameworkFeatures);
    }

    #[Test]
    public function controlConstructionWithFeatures(): void
    {
        $control = new Control(
            id: '164.312',
            framework: 'hipaa',
            title: 'Audit Controls',
            description: 'Hardware, software, and procedural mechanisms',
            status: ControlStatus::Partial,
            frameworkFeatures: ['audit_logging', 'session_management'],
        );

        self::assertSame(['audit_logging', 'session_management'], $control->frameworkFeatures);
        self::assertSame(ControlStatus::Partial, $control->status);
    }

    // ── VerificationResult ────────────────────────────────────────────

    #[Test]
    public function verificationResultPassFactory(): void
    {
        $result = VerificationResult::pass('CC6.1');

        self::assertSame('CC6.1', $result->controlId);
        self::assertTrue($result->passed);
        self::assertSame('Control verified', $result->message);
        self::assertNotNull($result->verifiedAt);
    }

    #[Test]
    public function verificationResultPassWithCustomMessage(): void
    {
        $result = VerificationResult::pass('CC6.1', 'Encryption enabled');

        self::assertSame('Encryption enabled', $result->message);
        self::assertTrue($result->passed);
    }

    #[Test]
    public function verificationResultFailFactory(): void
    {
        $result = VerificationResult::fail('CC6.1', 'HMAC key not configured');

        self::assertSame('CC6.1', $result->controlId);
        self::assertFalse($result->passed);
        self::assertSame('HMAC key not configured', $result->message);
        self::assertNotNull($result->verifiedAt);
    }

    #[Test]
    public function verificationResultDirectConstruction(): void
    {
        $result = new VerificationResult(
            controlId: 'REQ-7.1',
            passed: false,
        );

        self::assertSame('REQ-7.1', $result->controlId);
        self::assertFalse($result->passed);
        self::assertSame('', $result->message);
        self::assertNull($result->verifiedAt);
    }

    // ── ComplianceFramework ──────────────────────────────────────────────

    #[Test]
    public function complianceFrameworkHasSixteenCases(): void
    {
        self::assertCount(18, ComplianceFramework::cases());
    }

    #[Test]
    #[DataProvider('frameworkProvider')]
    public function complianceFrameworkBackedValues(ComplianceFramework $framework, string $expected): void
    {
        self::assertSame($expected, $framework->value);
    }

    /**
     * @return iterable<string, array{ComplianceFramework, string}>
     */
    public static function frameworkProvider(): iterable
    {
        yield 'PciDss' => [ComplianceFramework::PciDss, 'pci_dss'];
        yield 'Hipaa' => [ComplianceFramework::Hipaa, 'hipaa'];
        yield 'Gdpr' => [ComplianceFramework::Gdpr, 'gdpr'];
        yield 'Soc2' => [ComplianceFramework::Soc2, 'soc2'];
        yield 'Iso27001' => [ComplianceFramework::Iso27001, 'iso27001'];
        yield 'Ccpa' => [ComplianceFramework::Ccpa, 'ccpa'];
        yield 'NistCsf' => [ComplianceFramework::NistCsf, 'nist_csf'];
        yield 'Dora' => [ComplianceFramework::Dora, 'dora'];
        yield 'Psd2' => [ComplianceFramework::Psd2, 'psd2'];
        yield 'Eidas' => [ComplianceFramework::Eidas, 'eidas'];
        yield 'Nis2' => [ComplianceFramework::Nis2, 'nis2'];
        yield 'Hl7Fhir' => [ComplianceFramework::Hl7Fhir, 'hl7_fhir'];
        yield 'Iso42001' => [ComplianceFramework::Iso42001, 'iso42001'];
        yield 'Mdr' => [ComplianceFramework::Mdr, 'mdr'];
        yield 'Iso13485' => [ComplianceFramework::Iso13485, 'iso13485'];
        yield 'SwiftCsp' => [ComplianceFramework::SwiftCsp, 'swift_csp'];
    }

    #[Test]
    public function complianceFrameworkFromBackedValue(): void
    {
        self::assertSame(ComplianceFramework::PciDss, ComplianceFramework::from('pci_dss'));
        self::assertSame(ComplianceFramework::Iso42001, ComplianceFramework::from('iso42001'));
    }
}
