<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\OpenApi\Attribute\ApiDoc;
use Pulsar\Api\OpenApi\Attribute\ApiParam;
use Pulsar\Api\OpenApi\Attribute\ApiResponse;
use Pulsar\Api\OpenApi\EndpointMetadata;
use stdClass;

final class EndpointMetadataTest extends TestCase
{
    #[Test]
    public function constructs_with_required_fields(): void
    {
        $meta = new EndpointMetadata(
            path: '/api/users',
            methods: ['GET'],
        );

        self::assertSame('/api/users', $meta->path);
        self::assertSame(['GET'], $meta->methods);
        self::assertNull($meta->handlerClass);
        self::assertNull($meta->handlerMethod);
        self::assertNull($meta->doc);
        self::assertSame([], $meta->params);
        self::assertSame([], $meta->responses);
        self::assertSame([], $meta->middleware);
        self::assertSame([], $meta->securitySchemes);
    }

    #[Test]
    public function constructs_with_all_fields(): void
    {
        $doc = new ApiDoc(summary: 'List users', description: 'Returns all users');
        $param = new ApiParam(name: 'id', in: 'path');
        $response = new ApiResponse(status: 200, description: 'OK');

        $meta = new EndpointMetadata(
            path: '/api/users/{id}',
            methods: ['GET', 'PUT'],
            handlerClass: stdClass::class,
            handlerMethod: 'show',
            doc: $doc,
            params: [$param],
            responses: [$response],
            middleware: ['auth', 'throttle'],
            securitySchemes: ['bearer'],
        );

        self::assertSame('/api/users/{id}', $meta->path);
        self::assertSame(['GET', 'PUT'], $meta->methods);
        self::assertSame(stdClass::class, $meta->handlerClass);
        self::assertSame('show', $meta->handlerMethod);
        self::assertSame($doc, $meta->doc);
        self::assertCount(1, $meta->params);
        self::assertCount(1, $meta->responses);
        self::assertSame(['auth', 'throttle'], $meta->middleware);
        self::assertSame(['bearer'], $meta->securitySchemes);
    }

    #[Test]
    public function supports_multiple_methods(): void
    {
        $meta = new EndpointMetadata(
            path: '/api/resource',
            methods: ['GET', 'POST', 'PUT', 'DELETE'],
        );

        self::assertCount(4, $meta->methods);
        self::assertContains('DELETE', $meta->methods);
    }
}
