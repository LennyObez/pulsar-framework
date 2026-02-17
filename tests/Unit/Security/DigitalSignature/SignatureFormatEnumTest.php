<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\DigitalSignature;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\DigitalSignature\SignatureFormat;

#[CoversClass(SignatureFormat::class)]
final class SignatureFormatEnumTest extends TestCase
{
    #[Test]
    public function hasFourCases(): void
    {
        self::assertCount(4, SignatureFormat::cases());
    }

    #[Test]
    #[DataProvider('formatProvider')]
    public function backedValues(SignatureFormat $format, string $expected): void
    {
        self::assertSame($expected, $format->value);
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

    #[Test]
    public function fromBackedValueRoundTrips(): void
    {
        foreach (SignatureFormat::cases() as $format) {
            self::assertSame($format, SignatureFormat::from($format->value));
        }
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(SignatureFormat::tryFrom('unknown'));
    }
}
