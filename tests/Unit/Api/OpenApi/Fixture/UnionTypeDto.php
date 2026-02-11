<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi\Fixture;

final class UnionTypeDto
{
    public string|null $label = null;
    public string|int $value = '';
}
