<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Scope\Fixtures;

final readonly class ImmutableService
{
    public function __construct(
        public string $name,
    ) {}
}
