<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Fixtures;

/**
 * Role entity for testing BelongsToMany relations.
 */
final class RoleEntity
{
    public function __construct(
        public readonly int $id = 0,
        public readonly string $name = '',
    ) {}
}
