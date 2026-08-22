<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\DigitalSignature;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\DigitalSignature\TimestampToken;

#[CoversClass(TimestampToken::class)]
final class TimestampTokenTest extends TestCase
{
    #[Test]
    public function constructWithValidToken(): void
    {
        $timestamp = new DateTimeImmutable('2026-03-14T12:00:00+00:00');

        $token = new TimestampToken(
            valid: true,
            dataHash: 'abc123def456',
            hashAlgorithm: 'sha256',
            timestamp: $timestamp,
            tsaSubject: 'CN=Qualified TSA,O=Trust Provider',
            serialNumber: 'TSA-001-2026',
        );

        self::assertTrue($token->valid);
        self::assertSame('abc123def456', $token->dataHash);
        self::assertSame('sha256', $token->hashAlgorithm);
        self::assertSame($timestamp, $token->timestamp);
        self::assertSame('CN=Qualified TSA,O=Trust Provider', $token->tsaSubject);
        self::assertSame('TSA-001-2026', $token->serialNumber);
        self::assertSame('', $token->reason);
    }

    #[Test]
    public function constructWithInvalidToken(): void
    {
        $token = new TimestampToken(
            valid: false,
            dataHash: 'abc123',
            hashAlgorithm: 'sha256',
            timestamp: new DateTimeImmutable(),
            tsaSubject: 'CN=Unknown TSA',
            reason: 'Hash mismatch',
        );

        self::assertFalse($token->valid);
        self::assertSame('Hash mismatch', $token->reason);
    }

    #[Test]
    public function defaultsApplied(): void
    {
        $token = new TimestampToken(
            valid: true,
            dataHash: 'hash',
            hashAlgorithm: 'sha512',
            timestamp: new DateTimeImmutable(),
            tsaSubject: 'CN=TSA',
        );

        self::assertSame('', $token->serialNumber);
        self::assertSame('', $token->reason);
    }
}
