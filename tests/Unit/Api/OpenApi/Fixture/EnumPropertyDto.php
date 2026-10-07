<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi\Fixture;

final class EnumPropertyDto
{
    public StringBackedStatusEnum $status;

    public function __construct()
    {
        $this->status = StringBackedStatusEnum::Active;
    }
}
