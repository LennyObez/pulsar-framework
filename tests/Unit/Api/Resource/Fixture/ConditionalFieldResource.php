<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Resource\Fixture;

use Pulsar\Api\Resource\AbstractApiResource;
use Pulsar\Api\Resource\Attribute\ApiResource;
use Pulsar\Api\Resource\Attribute\Expose;

#[ApiResource(type: 'conditional_test')]
class ConditionalFieldResource extends AbstractApiResource
{
    #[Expose]
    public string $id = '';

    #[Expose]
    public mixed $email = '';
}
