<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Fixtures;

use Pulsar\Extension\Orm\Attribute\Column;
use Pulsar\Extension\Orm\Attribute\Id;
use Pulsar\Extension\Orm\Attribute\Relation;
use Pulsar\Extension\Orm\Attribute\Table;
use Pulsar\Extension\Orm\Attribute\Timestamps;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\RelationType;

#[Table(name: 'users')]
#[Timestamps]
final class UserEntity
{
    /** @param list<PostEntity> $posts */
    public function __construct(
        #[Id]
        #[Column(type: ColumnType::Integer)]
        public readonly int $id = 0,
        #[Column(type: ColumnType::String)]
        public readonly string $name = '',
        #[Column(type: ColumnType::String)]
        public readonly string $email = '',
        #[Relation(type: RelationType::HasMany, target: PostEntity::class, foreignKey: 'user_id')]
        public readonly array $posts = [],
    ) {}
}
