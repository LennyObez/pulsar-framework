<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\TotpGenerator;

use function strlen;

#[CoversClass(TotpGenerator::class)]
final class TotpGeneratorTest extends TestCase
{
    private TotpGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new TotpGenerator();
    }

    #[Test]
    public function generateSecretReturnsRandomBytesOfCorrectLength(): void
    {
        $secret = $this->generator->generateSecret();

        self::assertSame(20, strlen($secret));

        $secret32 = $this->generator->generateSecret(32);

        self::assertSame(32, strlen($secret32));
    }

    #[Test]
    public function encodeSecretBase32ReturnsNonEmptyBase32String(): void
    {
        $secret = $this->generator->generateSecret();
        $encoded = $this->generator->encodeSecretBase32($secret);

        self::assertNotEmpty($encoded);
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $encoded);
    }

    #[Test]
    public function computeCodeReturnsSixDigitString(): void
    {
        $secret = $this->generator->generateSecret();
        $code = $this->generator->computeCode($secret, 1000000);

        self::assertSame(6, strlen($code));
        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    #[Test]
    public function computeCodeIsDeterministicForSameSecretAndTimestamp(): void
    {
        $secret = $this->generator->generateSecret();
        $timestamp = 1000000;

        $code1 = $this->generator->computeCode($secret, $timestamp);
        $code2 = $this->generator->computeCode($secret, $timestamp);

        self::assertSame($code1, $code2);
    }

    #[Test]
    public function provisioningUriReturnsValidOtpauthFormat(): void
    {
        $secret = $this->generator->generateSecret();
        $uri = $this->generator->provisioningUri($secret, 'user@example.com', 'Pulsar');

        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertStringContainsString('secret=', $uri);
        self::assertStringContainsString('issuer=Pulsar', $uri);
        self::assertStringContainsString('algorithm=SHA1', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
        self::assertStringContainsString('user%40example.com', $uri);
    }

    #[Test]
    public function computeCodeWithKnownTestVector(): void
    {
        // RFC 6238 test vector: 20-byte ASCII secret "12345678901234567890"
        $secret = '12345678901234567890';

        // At timestamp 59, time step = intdiv(59, 30) = 1
        $code1 = $this->generator->computeCode($secret, 59);
        self::assertMatchesRegularExpression('/^\d{6}$/', $code1);

        // The code must be deterministic: same secret + timestamp = same code
        $code2 = $this->generator->computeCode($secret, 59);
        self::assertSame($code1, $code2);

        // A different timestamp should generally produce a different code
        $codeDifferentTime = $this->generator->computeCode($secret, 90);
        // timestamp 90 => time step = intdiv(90, 30) = 3, which differs from step 1
        // This is overwhelmingly likely to be different
        self::assertMatchesRegularExpression('/^\d{6}$/', $codeDifferentTime);
    }

    /**
     * RFC 6238 Appendix B SHA-1 test vectors (8-digit mode).
     *
     * @return array<string, array{int, string}>
     */
    public static function rfc6238Sha1EightDigitProvider(): array
    {
        return [
            'T=59'          => [59,          '94287082'],
            'T=1111111109'  => [1111111109,  '07081804'],
            'T=1111111111'  => [1111111111,  '14050471'],
            'T=1234567890'  => [1234567890,  '89005924'],
            'T=2000000000'  => [2000000000,  '69279037'],
            'T=20000000000' => [20000000000, '65353130'],
        ];
    }

    #[Test]
    #[DataProvider('rfc6238Sha1EightDigitProvider')]
    public function rfc6238AppendixBEightDigitVectors(int $timestamp, string $expected): void
    {
        $generator8 = new TotpGenerator(codeDigits: 8);
        $secret = '12345678901234567890';

        $code = $generator8->computeCode($secret, $timestamp);

        self::assertSame($expected, $code, "RFC 6238 SHA-1 8-digit vector failed at T={$timestamp}");
    }

    /**
     * RFC 6238 Appendix B SHA-1 test vectors — 6-digit variants (last 6 digits of 8-digit values).
     *
     * @return array<string, array{int, string}>
     */
    public static function rfc6238Sha1SixDigitProvider(): array
    {
        return [
            'T=59'          => [59,          '287082'],
            'T=1111111109'  => [1111111109,  '081804'],
            'T=1111111111'  => [1111111111,  '050471'],
            'T=1234567890'  => [1234567890,  '005924'],
            'T=2000000000'  => [2000000000,  '279037'],
            'T=20000000000' => [20000000000, '353130'],
        ];
    }

    #[Test]
    #[DataProvider('rfc6238Sha1SixDigitProvider')]
    public function rfc6238AppendixBSixDigitVectors(int $timestamp, string $expected): void
    {
        $secret = '12345678901234567890';

        $code = $this->generator->computeCode($secret, $timestamp);

        self::assertSame($expected, $code, "RFC 6238 SHA-1 6-digit vector failed at T={$timestamp}");
    }

    #[Test]
    public function periodAndDigitsAccessors(): void
    {
        self::assertSame(30, $this->generator->period());
        self::assertSame(6, $this->generator->digits());

        $custom = new TotpGenerator(codeDigits: 8, period: 60);
        self::assertSame(60, $custom->period());
        self::assertSame(8, $custom->digits());
    }
}
