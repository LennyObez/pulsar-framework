<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\KeyLifecycle;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\KeyLifecycle\CertificateType;
use Pulsar\Security\KeyLifecycle\CertificateWarningLevel;
use Pulsar\Security\KeyLifecycle\KeyType;

#[CoversNothing]
final class KeyLifecycleEnumTest extends TestCase
{
    // ── KeyType ───────────────────────────────────────────────────────

    #[Test]
    public function keyTypeHasSevenCases(): void
    {
        self::assertCount(7, KeyType::cases());
    }

    #[Test]
    #[DataProvider('keyTypeProvider')]
    public function keyTypeBackedValues(KeyType $type, string $expected): void
    {
        self::assertSame($expected, $type->value);
    }

    /**
     * @return iterable<string, array{KeyType, string}>
     */
    public static function keyTypeProvider(): iterable
    {
        yield 'Master' => [KeyType::Master, 'master'];
        yield 'Encryption' => [KeyType::Encryption, 'encryption'];
        yield 'Audit' => [KeyType::Audit, 'audit'];
        yield 'Tls' => [KeyType::Tls, 'tls'];
        yield 'Signing' => [KeyType::Signing, 'signing'];
        yield 'FipsModule' => [KeyType::FipsModule, 'fips_module'];
        yield 'Tokenization' => [KeyType::Tokenization, 'tokenization'];
    }

    #[Test]
    public function keyTypeFromBackedValue(): void
    {
        self::assertSame(KeyType::Master, KeyType::from('master'));
        self::assertSame(KeyType::Tokenization, KeyType::from('tokenization'));
        self::assertSame(KeyType::FipsModule, KeyType::from('fips_module'));
    }

    // ── CertificateType ───────────────────────────────────────────────

    #[Test]
    public function certificateTypeHasFourCases(): void
    {
        self::assertCount(4, CertificateType::cases());
    }

    #[Test]
    #[DataProvider('certificateTypeProvider')]
    public function certificateTypeBackedValues(CertificateType $type, string $expected): void
    {
        self::assertSame($expected, $type->value);
    }

    /**
     * @return iterable<string, array{CertificateType, string}>
     */
    public static function certificateTypeProvider(): iterable
    {
        yield 'Tls' => [CertificateType::Tls, 'tls'];
        yield 'Signing' => [CertificateType::Signing, 'signing'];
        yield 'FipsModule' => [CertificateType::FipsModule, 'fips_module'];
        yield 'ClientAuth' => [CertificateType::ClientAuth, 'client_auth'];
    }

    #[Test]
    public function certificateTypeFromBackedValue(): void
    {
        self::assertSame(CertificateType::Tls, CertificateType::from('tls'));
        self::assertSame(CertificateType::ClientAuth, CertificateType::from('client_auth'));
    }

    // ── CertificateWarningLevel ───────────────────────────────────────

    #[Test]
    public function certificateWarningLevelHasFourCases(): void
    {
        self::assertCount(4, CertificateWarningLevel::cases());
    }

    #[Test]
    #[DataProvider('warningLevelProvider')]
    public function certificateWarningLevelBackedValues(CertificateWarningLevel $level, string $expected): void
    {
        self::assertSame($expected, $level->value);
    }

    /**
     * @return iterable<string, array{CertificateWarningLevel, string}>
     */
    public static function warningLevelProvider(): iterable
    {
        yield 'Info' => [CertificateWarningLevel::Info, 'info'];
        yield 'Warning' => [CertificateWarningLevel::Warning, 'warning'];
        yield 'Critical' => [CertificateWarningLevel::Critical, 'critical'];
        yield 'Expired' => [CertificateWarningLevel::Expired, 'expired'];
    }

    #[Test]
    public function certificateWarningLevelFromBackedValue(): void
    {
        self::assertSame(CertificateWarningLevel::Info, CertificateWarningLevel::from('info'));
        self::assertSame(CertificateWarningLevel::Expired, CertificateWarningLevel::from('expired'));
    }
}
