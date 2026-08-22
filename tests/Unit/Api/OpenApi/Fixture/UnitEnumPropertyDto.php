<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi\Fixture;

final class UnitEnumPropertyDto
{
    public UnitColorEnum $color;

    public function __construct()
    {
        $this->color = UnitColorEnum::Red;
    }
}
