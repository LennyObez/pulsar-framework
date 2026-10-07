<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi\Fixture;

use Pulsar\Api\OpenApi\Attribute\ApiDoc;
use Pulsar\Api\OpenApi\Attribute\ApiParam;
use Pulsar\Api\OpenApi\Attribute\ApiResponse;

#[ApiDoc(summary: 'User management', tags: ['Users'])]
final class AnnotatedController
{
    #[ApiDoc(summary: 'List users', description: 'Returns paginated users', tags: ['Users'], operationId: 'listUsers')]
    #[ApiParam(name: 'page', in: 'query', type: 'integer', description: 'Page number')]
    #[ApiParam(name: 'limit', in: 'query', type: 'integer', description: 'Items per page')]
    #[ApiResponse(status: 200, description: 'Success')]
    #[ApiResponse(status: 401, description: 'Unauthorized')]
    public function index(): void {}

    public function show(): void {}
}
