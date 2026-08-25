<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Evidence;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Evidence\EvidenceType;

#[CoversNothing]
final class EvidenceTypeTest extends TestCase
{
    #[Test]
    public function hasTenCases(): void
    {
        self::assertCount(10, EvidenceType::cases());
    }

    #[Test]
    #[DataProvider('evidenceTypeProvider')]
    public function backedValues(EvidenceType $type, string $expected): void
    {
        self::assertSame($expected, $type->value);
    }

    /**
     * @return iterable<string, array{EvidenceType, string}>
     */
    public static function evidenceTypeProvider(): iterable
    {
        yield 'VerificationRun' => [EvidenceType::VerificationRun, 'verification_evidence'];
        yield 'Configuration' => [EvidenceType::Configuration, 'configuration'];
        yield 'AuditLog' => [EvidenceType::AuditLog, 'audit_log'];
        yield 'TestResult' => [EvidenceType::TestResult, 'test_result'];
        yield 'AccessControl' => [EvidenceType::AccessControl, 'access_control'];
        yield 'Encryption' => [EvidenceType::Encryption, 'encryption'];
        yield 'Monitoring' => [EvidenceType::Monitoring, 'monitoring'];
        yield 'ChangeManagement' => [EvidenceType::ChangeManagement, 'change_management'];
        yield 'Vulnerability' => [EvidenceType::Vulnerability, 'vulnerability'];
        yield 'Sbom' => [EvidenceType::Sbom, 'sbom'];
    }

    #[Test]
    public function fromBackedValue(): void
    {
        self::assertSame(EvidenceType::AuditLog, EvidenceType::from('audit_log'));
        self::assertSame(EvidenceType::Sbom, EvidenceType::from('sbom'));
    }

    #[Test]
    public function theVerificationRunValueIsTheOneAlreadyInStoredRecords(): void
    {
        // Records written before this case existed carry the literal string, and
        // an evidence chain is not worth rewriting for a case name.
        self::assertSame('verification_evidence', EvidenceType::VerificationRun->value);
    }
}
