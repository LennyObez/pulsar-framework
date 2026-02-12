<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Resource\Fixture;

use Pulsar\Api\Resource\AbstractApiResource;

/**
 * Resource missing the #[ApiResource] attribute — should throw on metadata resolution.
 */
class NoAttributeResource extends AbstractApiResource
{
    public string $id = '';
}
