<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\RelationType;

final class RelationTypeTest extends TestCase
{
    #[Test]
    public function allCasesHaveBackingValues(): void
    {
        self::assertSame('belongs_to', RelationType::BelongsTo->value);
        self::assertSame('has_one', RelationType::HasOne->value);
        self::assertSame('has_many', RelationType::HasMany->value);
        self::assertSame('belongs_to_many', RelationType::BelongsToMany->value);
        self::assertSame('morph_to', RelationType::MorphTo->value);
        self::assertSame('morph_many', RelationType::MorphMany->value);
    }

    #[Test]
    public function totalCaseCount(): void
    {
        self::assertCount(8, RelationType::cases());
    }

    #[Test]
    public function newCasesHaveBackingValues(): void
    {
        self::assertSame('has_many_through', RelationType::HasManyThrough->value);
        self::assertSame('morph_to_many', RelationType::MorphToMany->value);
    }

    #[Test]
    public function isPolymorphicForStandardTypes(): void
    {
        self::assertFalse(RelationType::BelongsTo->isPolymorphic());
        self::assertFalse(RelationType::HasOne->isPolymorphic());
        self::assertFalse(RelationType::HasMany->isPolymorphic());
        self::assertFalse(RelationType::BelongsToMany->isPolymorphic());
        self::assertFalse(RelationType::HasManyThrough->isPolymorphic());
    }

    #[Test]
    public function isPolymorphicForMorphTypes(): void
    {
        self::assertTrue(RelationType::MorphTo->isPolymorphic());
        self::assertTrue(RelationType::MorphMany->isPolymorphic());
        self::assertTrue(RelationType::MorphToMany->isPolymorphic());
    }
}
