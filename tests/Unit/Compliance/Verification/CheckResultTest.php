<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Verification\CheckResult;
use Pulsar\Compliance\Verification\CheckStatus;
use Pulsar\Compliance\Verification\ComplianceCheckDomain;

#[CoversClass(CheckResult::class)]
final class CheckResultTest extends TestCase
{
    public function testPassFactoryCreatesPassResult(): void
    {
        $result = CheckResult::pass(
            'encryption.at_rest',
            'Encryption is active.',
            ComplianceCheckDomain::Encryption,
            ['AES-256-GCM'],
        );

        self::assertSame('encryption.at_rest', $result->checkId);
        self::assertSame(CheckStatus::Pass, $result->status);
        self::assertSame('Encryption is active.', $result->message);
        self::assertSame(ComplianceCheckDomain::Encryption, $result->domain);
        self::assertSame(['AES-256-GCM'], $result->evidence);
        self::assertSame([], $result->remediations);
        self::assertNotNull($result->verifiedAt);
    }

    public function testFailFactoryCreatesFailResult(): void
    {
        $result = CheckResult::fail(
            'runtime.db_tls',
            'Database TLS not active.',
            ComplianceCheckDomain::TransportSecurity,
            ['Enable TLS in config.'],
        );

        self::assertSame('runtime.db_tls', $result->checkId);
        self::assertSame(CheckStatus::Fail, $result->status);
        self::assertSame('Database TLS not active.', $result->message);
        self::assertSame(ComplianceCheckDomain::TransportSecurity, $result->domain);
        self::assertSame([], $result->evidence);
        self::assertSame(['Enable TLS in config.'], $result->remediations);
        self::assertNotNull($result->verifiedAt);
    }

    public function testSkipFactoryCreatesSkipResult(): void
    {
        $result = CheckResult::skip(
            'runtime.fips',
            'Not required.',
            ComplianceCheckDomain::Encryption,
        );

        self::assertSame(CheckStatus::Skip, $result->status);
        self::assertSame([], $result->evidence);
        self::assertSame([], $result->remediations);
    }

    public function testConstructorDefaults(): void
    {
        $result = new CheckResult(
            checkId: 'test',
            status: CheckStatus::Pass,
            message: 'ok',
        );

        self::assertSame(ComplianceCheckDomain::Encryption, $result->domain);
        self::assertSame([], $result->evidence);
        self::assertSame([], $result->remediations);
        self::assertNull($result->verifiedAt);
    }
}
