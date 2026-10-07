<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi\Fixture;

enum IntBackedPriorityEnum: int
{
    case Low = 1;
    case Medium = 2;
    case High = 3;
}
