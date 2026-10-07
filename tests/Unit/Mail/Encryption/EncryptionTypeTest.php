<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Encryption;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Encryption\EncryptionType;

use function array_map;

#[CoversNothing]
final class EncryptionTypeTest extends TestCase
{
    #[Test]
    public function smimeHasCorrectValue(): void
    {
        self::assertSame('smime', EncryptionType::Smime->value);
    }

    #[Test]
    public function pgpHasCorrectValue(): void
    {
        self::assertSame('pgp', EncryptionType::Pgp->value);
    }

    #[Test]
    public function hasExactlyTwoCases(): void
    {
        self::assertCount(2, EncryptionType::cases());
    }

    #[Test]
    public function canBeCreatedFromString(): void
    {
        self::assertSame(EncryptionType::Smime, EncryptionType::from('smime'));
        self::assertSame(EncryptionType::Pgp, EncryptionType::from('pgp'));
    }

    #[Test]
    public function returnsNullForInvalidStringViaTryFrom(): void
    {
        self::assertNull(EncryptionType::tryFrom('invalid'));
    }

    #[Test]
    public function allCasesHaveNonEmptyStringValues(): void
    {
        $values = array_map(
            static fn(EncryptionType $type): string => $type->value,
            EncryptionType::cases(),
        );

        foreach ($values as $value) {
            self::assertNotSame('', $value);
        }
    }
}
