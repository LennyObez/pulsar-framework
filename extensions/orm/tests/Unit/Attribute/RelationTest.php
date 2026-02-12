<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Attribute\Relation;
use Pulsar\Extension\Orm\Domain\RelationType;

final class RelationTest extends TestCase
{
    #[Test]
    public function belongsToRelation(): void
    {
        $rel = new Relation(
            type: RelationType::BelongsTo,
            target: 'App\\Entity\\User',
            foreignKey: 'user_id',
        );

        self::assertSame(RelationType::BelongsTo, $rel->type);
        self::assertSame('App\\Entity\\User', $rel->target);
        self::assertSame('user_id', $rel->foreignKey);
        self::assertNull($rel->localKey);
        self::assertNull($rel->pivotTable);
    }

    #[Test]
    public function belongsToManyWithPivot(): void
    {
        $rel = new Relation(
            type: RelationType::BelongsToMany,
            target: 'App\\Entity\\Role',
            pivotTable: 'user_roles',
            pivotForeignKey: 'user_id',
            pivotRelatedKey: 'role_id',
        );

        self::assertSame(RelationType::BelongsToMany, $rel->type);
        self::assertSame('user_roles', $rel->pivotTable);
        self::assertSame('user_id', $rel->pivotForeignKey);
        self::assertSame('role_id', $rel->pivotRelatedKey);
    }
}
