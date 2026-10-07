<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Eidas\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Eidas\Domain\SealInfo;
use Pulsar\Extension\Eidas\Domain\SignatureFormat;

#[CoversClass(SealInfo::class)]
final class SealInfoTest extends TestCase
{
    #[Test]
    public function toArraySerializesAllFields(): void
    {
        $sealedAt = new DateTimeImmutable('2025-06-15 10:30:00.123456+00:00');
        $seal = new SealInfo(
            valid: true,
            organizationName: 'Acme Corp',
            organizationId: 'DE123456789',
            format: SignatureFormat::CAdES,
            isQualified: true,
            sealedAt: $sealedAt,
            reason: 'Invoice authentication',
        );

        $array = $seal->toArray();

        self::assertTrue($array['valid']);
        self::assertSame('Acme Corp', $array['organization_name']);
        self::assertSame('DE123456789', $array['organization_id']);
        self::assertSame('cades', $array['format']);
        self::assertTrue($array['is_qualified']);
        self::assertStringContainsString('2025-06-15', $array['sealed_at']);
        self::assertSame('Invoice authentication', $array['reason']);
    }

    #[Test]
    public function invalidSealSerializesCorrectly(): void
    {
        $seal = new SealInfo(
            valid: false,
            organizationName: 'Unknown',
            organizationId: '',
            format: SignatureFormat::XAdES,
            isQualified: false,
            sealedAt: new DateTimeImmutable(),
            reason: 'Certificate expired',
        );

        $array = $seal->toArray();

        self::assertFalse($array['valid']);
        self::assertFalse($array['is_qualified']);
        self::assertSame('Certificate expired', $array['reason']);
    }

    #[Test]
    public function defaultReasonIsEmptyString(): void
    {
        $seal = new SealInfo(
            valid: true,
            organizationName: 'Test',
            organizationId: 'ID-1',
            format: SignatureFormat::PAdES,
            isQualified: true,
            sealedAt: new DateTimeImmutable(),
        );

        self::assertSame('', $seal->reason);
        self::assertSame('', $seal->toArray()['reason']);
    }

    /**
     * @param SignatureFormat $format
     * @param string $expectedValue
     */
    #[Test]
    #[DataProvider('formatProvider')]
    public function formatSerializesCorrectly(SignatureFormat $format, string $expectedValue): void
    {
        $seal = new SealInfo(
            valid: true,
            organizationName: 'Test',
            organizationId: 'ID-1',
            format: $format,
            isQualified: false,
            sealedAt: new DateTimeImmutable(),
        );

        self::assertSame($expectedValue, $seal->toArray()['format']);
    }

    /**
     * @return iterable<string, array{SignatureFormat, string}>
     */
    public static function formatProvider(): iterable
    {
        yield 'XAdES' => [SignatureFormat::XAdES, 'xades'];
        yield 'PAdES' => [SignatureFormat::PAdES, 'pades'];
        yield 'CAdES' => [SignatureFormat::CAdES, 'cades'];
        yield 'JAdES' => [SignatureFormat::JAdES, 'jades'];
    }
}
