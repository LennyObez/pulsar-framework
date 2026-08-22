<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Fixtures;

use Pulsar\Extension\Orm\Attribute\Column;
use Pulsar\Extension\Orm\Attribute\Id;
use Pulsar\Extension\Orm\Attribute\Relation;
use Pulsar\Extension\Orm\Attribute\Table;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\RelationType;

/**
 * Fixture entity with HasManyThrough relation for MetadataCompiler test.
 *
 * Country → Users → Posts (country has many posts through users).
 */
#[Table(name: 'countries')]
final class HasManyThroughEntity
{
    /** @param list<PostEntity> $posts */
    public function __construct(
        #[Id]
        #[Column(type: ColumnType::Integer)]
        public readonly int $id = 0,
        #[Column(type: ColumnType::String)]
        public readonly string $name = '',
        #[Relation(
            type: RelationType::HasManyThrough,
            target: PostEntity::class,
            throughEntity: UserEntity::class,
            throughForeignKey: 'country_id',
            throughLocalKey: 'user_id',
        )]
        public readonly array $posts = [],
    ) {}
}
