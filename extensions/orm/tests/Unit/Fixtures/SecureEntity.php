<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Fixtures;

use Pulsar\Extension\Orm\Attribute\Column;
use Pulsar\Extension\Orm\Attribute\Encrypted;
use Pulsar\Extension\Orm\Attribute\Id;
use Pulsar\Extension\Orm\Attribute\Table;
use Pulsar\Extension\Orm\Domain\ColumnType;

#[Table(name: 'secure_entities')]
final class SecureEntity
{
    public function __construct(
        #[Id]
        #[Column(type: ColumnType::Integer)]
        public readonly int $id = 0,
        #[Column(type: ColumnType::String)]
        #[Encrypted]
        public readonly string $secret = '',
    ) {}
}
