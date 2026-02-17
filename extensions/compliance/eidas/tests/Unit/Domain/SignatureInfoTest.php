<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Eidas\Domain\SignatureFormat;
use Pulsar\Extension\Eidas\Domain\SignatureInfo;

final class SignatureInfoTest extends TestCase
{
    #[Test]
    public function toArrayContainsAllFields(): void
    {
        $info = new SignatureInfo(
            valid: true,
            signerName: 'John Doe',
            signerIdentifier: 'signer_001',
            format: SignatureFormat::JAdES,
            isQualified: true,
            signedAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
            trustChain: ['CN=John Doe', 'CN=Intermediate CA', 'CN=Root CA'],
        );

        /** @var array<string, mixed> $array */
        $array = $info->toArray();

        self::assertTrue($array['valid']);
        self::assertSame('John Doe', $array['signer_name']);
        self::assertSame('signer_001', $array['signer_identifier']);
        self::assertSame('jades', $array['format']);
        self::assertTrue($array['is_qualified']);
        /** @var list<string> $trustChain */
        $trustChain = $array['trust_chain'];
        self::assertCount(3, $trustChain);
        self::assertSame('', $array['reason']);
    }

    #[Test]
    public function invalidSignatureCarriesReason(): void
    {
        $info = new SignatureInfo(
            valid: false,
            signerName: 'Unknown',
            signerIdentifier: '',
            format: SignatureFormat::XAdES,
            isQualified: false,
            signedAt: new DateTimeImmutable(),
            reason: 'Certificate expired',
        );

        self::assertFalse($info->valid);
        self::assertSame('Certificate expired', $info->reason);
    }
}
