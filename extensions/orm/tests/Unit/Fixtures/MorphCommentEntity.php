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
 * Fixture entity with MorphTo relation for MetadataCompiler test.
 */
#[Table(name: 'comments')]
final class MorphCommentEntity
{
    public function __construct(
        #[Id]
        #[Column(type: ColumnType::Integer)]
        public readonly int $id = 0,
        #[Column(type: ColumnType::String)]
        public readonly string $body = '',
        #[Column(name: 'commentable_type', type: ColumnType::String)]
        public readonly string $commentableType = '',
        #[Column(name: 'commentable_id', type: ColumnType::Integer)]
        public readonly int $commentableId = 0,
        #[Relation(
            type: RelationType::MorphTo,
            target: PostEntity::class,
            morphTypeColumn: 'commentable_type',
            morphIdColumn: 'commentable_id',
        )]
        public ?object $commentable = null,
    ) {}
}
