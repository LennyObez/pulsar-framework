<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\Schema\RelationshipDefinition;
use Pulsar\Codegen\Schema\RelationType;

#[CoversClass(RelationshipDefinition::class)]
final class RelationshipDefinitionTest extends TestCase
{
    #[Test]
    public function constructBelongsTo(): void
    {
        $rel = new RelationshipDefinition(
            type: RelationType::BelongsTo,
            relatedEntity: 'User',
            foreignKey: 'user_id',
            localKey: 'id',
        );

        self::assertSame(RelationType::BelongsTo, $rel->type);
        self::assertSame('User', $rel->relatedEntity);
        self::assertSame('user_id', $rel->foreignKey);
        self::assertSame('id', $rel->localKey);
        self::assertNull($rel->pivotTable);
    }

    #[Test]
    public function constructBelongsToManyWithPivot(): void
    {
        $rel = new RelationshipDefinition(
            type: RelationType::BelongsToMany,
            relatedEntity: 'Role',
            foreignKey: 'user_id',
            localKey: 'role_id',
            pivotTable: 'user_roles',
        );

        self::assertSame(RelationType::BelongsToMany, $rel->type);
        self::assertSame('user_roles', $rel->pivotTable);
    }

    #[Test]
    public function toArrayProducesExpectedStructure(): void
    {
        $rel = new RelationshipDefinition(
            type: RelationType::HasMany,
            relatedEntity: 'Post',
            foreignKey: 'author_id',
            localKey: 'id',
        );

        $array = $rel->toArray();

        self::assertSame('has_many', $array['type']);
        self::assertSame('Post', $array['relatedEntity']);
        self::assertSame('author_id', $array['foreignKey']);
        self::assertSame('id', $array['localKey']);
        self::assertNull($array['pivotTable']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = new RelationshipDefinition(
            type: RelationType::BelongsToMany,
            relatedEntity: 'Tag',
            foreignKey: 'post_id',
            localKey: 'tag_id',
            pivotTable: 'post_tags',
        );

        $restored = RelationshipDefinition::fromArray($original->toArray());

        self::assertSame($original->type, $restored->type);
        self::assertSame($original->relatedEntity, $restored->relatedEntity);
        self::assertSame($original->foreignKey, $restored->foreignKey);
        self::assertSame($original->localKey, $restored->localKey);
        self::assertSame($original->pivotTable, $restored->pivotTable);
    }

    #[Test]
    public function fromArrayHandlesMissingPivotTable(): void
    {
        $rel = RelationshipDefinition::fromArray([
            'type' => 'belongs_to',
            'relatedEntity' => 'Category',
            'foreignKey' => 'category_id',
            'localKey' => 'id',
        ]);

        self::assertNull($rel->pivotTable);
    }
}
