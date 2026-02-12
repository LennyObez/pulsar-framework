<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\RelationMetadata;
use Pulsar\Extension\Orm\Domain\RelationType;

final class RelationMetadataTest extends TestCase
{
    #[Test]
    public function belongsToRelation(): void
    {
        $rel = new RelationMetadata(
            propertyName: 'author',
            type: RelationType::BelongsTo,
            targetEntity: 'App\\Entity\\User',
            foreignKey: 'author_id',
            localKey: 'id',
        );

        self::assertSame('author', $rel->propertyName);
        self::assertSame(RelationType::BelongsTo, $rel->type);
        self::assertSame('App\\Entity\\User', $rel->targetEntity);
        self::assertSame('author_id', $rel->foreignKey);
        self::assertSame('id', $rel->localKey);
        self::assertNull($rel->pivotTable);
    }

    #[Test]
    public function belongsToManyWithPivot(): void
    {
        $rel = new RelationMetadata(
            propertyName: 'tags',
            type: RelationType::BelongsToMany,
            targetEntity: 'App\\Entity\\Tag',
            foreignKey: 'post_id',
            localKey: 'id',
            pivotTable: 'post_tags',
            pivotForeignKey: 'post_id',
            pivotRelatedKey: 'tag_id',
        );

        self::assertSame('post_tags', $rel->pivotTable);
        self::assertSame('post_id', $rel->pivotForeignKey);
        self::assertSame('tag_id', $rel->pivotRelatedKey);
    }
}
