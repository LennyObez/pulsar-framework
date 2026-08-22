<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\Identifier;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;

final class IdentifierTest extends TestCase
{
    #[Test]
    public function fromValidIdentifier(): void
    {
        $id = Identifier::from('users');

        self::assertSame('users', $id->value);
        self::assertSame('users', $id->toString());
    }

    #[Test]
    public function fromRejectsInvalidIdentifier(): void
    {
        $this->expectException(QueryBuilderException::class);

        (void) Identifier::from('invalid-name');
    }

    #[Test]
    public function equalsReturnsTrueForSameValue(): void
    {
        $a = Identifier::from('users');
        $b = Identifier::from('users');

        self::assertTrue($a->equals($b));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentValues(): void
    {
        $a = Identifier::from('users');
        $b = Identifier::from('posts');

        self::assertFalse($a->equals($b));
    }

    #[Test]
    public function fromAcceptsUnderscores(): void
    {
        $id = Identifier::from('user_roles');

        self::assertSame('user_roles', $id->value);
    }

    #[Test]
    public function fromAcceptsLeadingUnderscore(): void
    {
        $id = Identifier::from('_internal');

        self::assertSame('_internal', $id->value);
    }
}
