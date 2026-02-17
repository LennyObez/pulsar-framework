<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Fixtures;

final class OrderEntity
{
    public function __construct(
        public readonly string $id = '',
    ) {}
}
