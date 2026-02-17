<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\DigitalSignature;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\DigitalSignature\SignatureFormat;
use Pulsar\Security\DigitalSignature\SignatureInfo;

#[CoversClass(SignatureInfo::class)]
final class SignatureInfoTest extends TestCase
{
    #[Test]
    public function constructWithValidSignature(): void
    {
        $signedAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $expiresAt = new DateTimeImmutable('2027-01-01T00:00:00+00:00');

        $info = new SignatureInfo(
            valid: true,
            format: SignatureFormat::CAdES,
            signerSubject: 'CN=Test User,O=Test Org',
            signedAt: $signedAt,
            expiresAt: $expiresAt,
            qualified: true,
            trustChain: ['CN=Test User', 'CN=Intermediate CA', 'CN=Root CA'],
        );

        self::assertTrue($info->valid);
        self::assertSame(SignatureFormat::CAdES, $info->format);
        self::assertSame('CN=Test User,O=Test Org', $info->signerSubject);
        self::assertSame($signedAt, $info->signedAt);
        self::assertSame($expiresAt, $info->expiresAt);
        self::assertTrue($info->qualified);
        self::assertCount(3, $info->trustChain);
        self::assertSame('', $info->reason);
    }

    #[Test]
    public function constructWithInvalidSignature(): void
    {
        $info = new SignatureInfo(
            valid: false,
            format: SignatureFormat::PAdES,
            signerSubject: 'CN=Unknown',
            signedAt: new DateTimeImmutable(),
            reason: 'Certificate expired',
        );

        self::assertFalse($info->valid);
        self::assertSame('Certificate expired', $info->reason);
        self::assertNull($info->expiresAt);
        self::assertFalse($info->qualified);
        self::assertSame([], $info->trustChain);
    }

    #[Test]
    public function defaultValuesAreApplied(): void
    {
        $info = new SignatureInfo(
            valid: true,
            format: SignatureFormat::XAdES,
            signerSubject: 'CN=Signer',
            signedAt: new DateTimeImmutable(),
        );

        self::assertNull($info->expiresAt);
        self::assertFalse($info->qualified);
        self::assertSame([], $info->trustChain);
        self::assertSame('', $info->reason);
    }
}
