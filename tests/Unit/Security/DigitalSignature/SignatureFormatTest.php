<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\DigitalSignature;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\DigitalSignature\SignatureFormat;

#[CoversNothing]
final class SignatureFormatTest extends TestCase
{
    #[Test]
    public function xadesHasCorrectValue(): void
    {
        self::assertSame('xades', SignatureFormat::XAdES->value);
    }

    #[Test]
    public function padesHasCorrectValue(): void
    {
        self::assertSame('pades', SignatureFormat::PAdES->value);
    }

    #[Test]
    public function cadesHasCorrectValue(): void
    {
        self::assertSame('cades', SignatureFormat::CAdES->value);
    }

    #[Test]
    public function jadesHasCorrectValue(): void
    {
        self::assertSame('jades', SignatureFormat::JAdES->value);
    }

    #[Test]
    public function casesReturnsFourFormats(): void
    {
        self::assertCount(4, SignatureFormat::cases());
    }

    #[Test]
    public function canBeCreatedFromString(): void
    {
        self::assertSame(SignatureFormat::CAdES, SignatureFormat::from('cades'));
    }

    #[Test]
    public function tryFromReturnsNullForUnknown(): void
    {
        self::assertNull(SignatureFormat::tryFrom('pkcs7'));
    }
}
