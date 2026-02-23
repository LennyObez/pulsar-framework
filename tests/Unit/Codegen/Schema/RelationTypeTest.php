<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\Schema\RelationType;

#[CoversClass(RelationType::class)]
final class RelationTypeTest extends TestCase
{
    #[Test]
    public function belongsToHasCorrectValue(): void
    {
        self::assertSame('belongs_to', RelationType::BelongsTo->value);
    }

    #[Test]
    public function hasOneHasCorrectValue(): void
    {
        self::assertSame('has_one', RelationType::HasOne->value);
    }

    #[Test]
    public function hasManyHasCorrectValue(): void
    {
        self::assertSame('has_many', RelationType::HasMany->value);
    }

    #[Test]
    public function belongsToManyHasCorrectValue(): void
    {
        self::assertSame('belongs_to_many', RelationType::BelongsToMany->value);
    }

    #[Test]
    public function fromStringValueReturnsCorrectCase(): void
    {
        self::assertSame(RelationType::BelongsTo, RelationType::from('belongs_to'));
        self::assertSame(RelationType::HasOne, RelationType::from('has_one'));
        self::assertSame(RelationType::HasMany, RelationType::from('has_many'));
        self::assertSame(RelationType::BelongsToMany, RelationType::from('belongs_to_many'));
    }

    #[Test]
    public function allFourCasesExist(): void
    {
        $cases = RelationType::cases();

        self::assertCount(4, $cases);
    }
}
