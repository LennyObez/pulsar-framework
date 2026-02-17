<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Verification\CheckStatus;
use Pulsar\Compliance\Verification\ComplianceCheckDomain;

#[CoversClass(CheckStatus::class)]
#[CoversClass(ComplianceCheckDomain::class)]
final class VerificationEnumTest extends TestCase
{
    // ── CheckStatus ───────────────────────────────────────────────────

    #[Test]
    public function checkStatusHasThreeCases(): void
    {
        self::assertCount(3, CheckStatus::cases());
    }

    #[Test]
    #[DataProvider('checkStatusProvider')]
    public function checkStatusBackedValues(CheckStatus $status, string $expected): void
    {
        self::assertSame($expected, $status->value);
    }

    /**
     * @return iterable<string, array{CheckStatus, string}>
     */
    public static function checkStatusProvider(): iterable
    {
        yield 'Pass' => [CheckStatus::Pass, 'pass'];
        yield 'Fail' => [CheckStatus::Fail, 'fail'];
        yield 'Skip' => [CheckStatus::Skip, 'skip'];
    }

    // ── ComplianceCheckDomain ─────────────────────────────────────────

    #[Test]
    public function complianceCheckDomainHasTenCases(): void
    {
        self::assertCount(10, ComplianceCheckDomain::cases());
    }

    #[Test]
    #[DataProvider('domainProvider')]
    public function complianceCheckDomainBackedValues(ComplianceCheckDomain $domain, string $expected): void
    {
        self::assertSame($expected, $domain->value);
    }

    /**
     * @return iterable<string, array{ComplianceCheckDomain, string}>
     */
    public static function domainProvider(): iterable
    {
        yield 'Encryption' => [ComplianceCheckDomain::Encryption, 'encryption'];
        yield 'Authentication' => [ComplianceCheckDomain::Authentication, 'authentication'];
        yield 'SessionManagement' => [ComplianceCheckDomain::SessionManagement, 'session_management'];
        yield 'AuditLogging' => [ComplianceCheckDomain::AuditLogging, 'audit_logging'];
        yield 'AccessControl' => [ComplianceCheckDomain::AccessControl, 'access_control'];
        yield 'TransportSecurity' => [ComplianceCheckDomain::TransportSecurity, 'transport_security'];
        yield 'InputValidation' => [ComplianceCheckDomain::InputValidation, 'input_validation'];
        yield 'RateLimiting' => [ComplianceCheckDomain::RateLimiting, 'rate_limiting'];
        yield 'DataRetention' => [ComplianceCheckDomain::DataRetention, 'data_retention'];
        yield 'IncidentResponse' => [ComplianceCheckDomain::IncidentResponse, 'incident_response'];
    }

    #[Test]
    public function fromBackedValues(): void
    {
        self::assertSame(CheckStatus::Pass, CheckStatus::from('pass'));
        self::assertSame(ComplianceCheckDomain::Encryption, ComplianceCheckDomain::from('encryption'));
        self::assertSame(ComplianceCheckDomain::IncidentResponse, ComplianceCheckDomain::from('incident_response'));
    }
}
