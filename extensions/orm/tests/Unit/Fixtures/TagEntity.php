<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Fixtures;

final class TagEntity
{
    public function __construct(
        public readonly int $id = 0,
        public readonly string $name = '',
    ) {}
}
