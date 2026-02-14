<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Scope\Fixtures;

final class StaticMutableService
{
    public static int $callCount = 0;

    public function __construct(
        public readonly string $name,
    ) {}
}
