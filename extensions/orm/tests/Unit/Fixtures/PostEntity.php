<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Fixtures;

use Pulsar\Extension\Orm\Attribute\Column;
use Pulsar\Extension\Orm\Attribute\Id;
use Pulsar\Extension\Orm\Attribute\SoftDelete;
use Pulsar\Extension\Orm\Attribute\Table;
use Pulsar\Extension\Orm\Domain\ColumnType;

#[Table(name: 'posts')]
#[SoftDelete]
final class PostEntity
{
    public function __construct(
        #[Id]
        #[Column(type: ColumnType::Integer)]
        public readonly int $id = 0,
        #[Column(name: 'user_id', type: ColumnType::Integer)]
        public readonly int $userId = 0,
    ) {}
}
