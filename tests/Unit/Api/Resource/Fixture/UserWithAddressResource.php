<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Resource\Fixture;

use Pulsar\Api\Resource\AbstractApiResource;
use Pulsar\Api\Resource\Attribute\ApiResource;
use Pulsar\Api\Resource\Attribute\Expose;

#[ApiResource(type: 'user_with_address')]
class UserWithAddressResource extends AbstractApiResource
{
    #[Expose]
    public string $id = '';

    #[Expose]
    public string $name = '';

    #[Expose]
    public NestedAddressResource $address;

    /** @var list<NestedAddressResource|string> */
    #[Expose]
    public array $tags = [];

    public function __construct()
    {
        $this->address = new NestedAddressResource();
    }
}
