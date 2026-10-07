<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi\Fixture;

final class IntEnumPropertyDto
{
    public IntBackedPriorityEnum $priority;

    public function __construct()
    {
        $this->priority = IntBackedPriorityEnum::Low;
    }
}
