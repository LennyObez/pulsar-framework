<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Fixtures;

final class FooEntity
{
    public function __construct(
        public readonly int $id = 0,
    ) {}
}
