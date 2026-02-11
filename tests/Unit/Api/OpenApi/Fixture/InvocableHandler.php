<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi\Fixture;

use Pulsar\Api\OpenApi\Attribute\ApiDoc;
use Pulsar\Api\OpenApi\Attribute\ApiResponse;

#[ApiDoc(summary: 'Invocable handler')]
final class InvocableHandler
{
    #[ApiDoc(summary: 'Handle request', description: 'Handles the invocable request')]
    #[ApiResponse(status: 200, description: 'OK')]
    public function __invoke(): void {}
}
