<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\Identifier;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;

#[CoversClass(Identifier::class)]
final class IdentifierTest extends TestCase
{
    #[Test]
    public function fromAcceptsSimpleTableName(): void
    {
        $id = Identifier::from('users');

        self::assertSame('users', $id->value);
        self::assertSame('users', $id->toString());
    }

    #[Test]
    public function fromAcceptsUnderscoreIdentifier(): void
    {
        $id = Identifier::from('user_profiles');

        self::assertSame('user_profiles', $id->value);
    }

    #[Test]
    public function fromAcceptsLeadingUnderscore(): void
    {
        $id = Identifier::from('_internal');

        self::assertSame('_internal', $id->value);
    }

    #[Test]
    public function fromAcceptsUpperCaseIdentifier(): void
    {
        $id = Identifier::from('Users');

        self::assertSame('Users', $id->value);
    }

    #[Test]
    public function fromRejectsEmptyString(): void
    {
        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessage('Invalid SQL identifier');

        $_ = Identifier::from('');
    }

    #[Test]
    public function fromRejectsLeadingDigit(): void
    {
        $this->expectException(QueryBuilderException::class);

        $_ = Identifier::from('1users');
    }

    #[Test]
    public function fromRejectsSpecialCharacters(): void
    {
        $this->expectException(QueryBuilderException::class);

        $_ = Identifier::from('user-name');
    }

    #[Test]
    public function fromRejectsDottedIdentifier(): void
    {
        $this->expectException(QueryBuilderException::class);

        $_ = Identifier::from('schema.table');
    }

    #[Test]
    public function fromRejectsSpaces(): void
    {
        $this->expectException(QueryBuilderException::class);

        $_ = Identifier::from('user name');
    }

    #[Test]
    public function equalsReturnsTrueForSameValue(): void
    {
        $a = Identifier::from('users');
        $b = Identifier::from('users');

        self::assertTrue($a->equals($b));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentValue(): void
    {
        $a = Identifier::from('users');
        $b = Identifier::from('orders');

        self::assertFalse($a->equals($b));
    }
}
