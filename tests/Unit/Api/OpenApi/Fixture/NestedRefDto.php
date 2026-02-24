<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi\Fixture;

final class NestedRefDto
{
    public AllTypesDto $nested;

    public function __construct()
    {
        $this->nested = new AllTypesDto(name: 'test', age: 1);
    }
}
