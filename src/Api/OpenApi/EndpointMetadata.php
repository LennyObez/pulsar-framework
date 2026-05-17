<?php

declare(strict_types=1);

namespace Pulsar\Api\OpenApi;

use Pulsar\Api\Api;
use Pulsar\Api\OpenApi\Attribute\ApiDoc;
use Pulsar\Api\OpenApi\Attribute\ApiParam;
use Pulsar\Api\OpenApi\Attribute\ApiResponse;

/**
 * Pre-scanned endpoint metadata collected at build time.
 *
 * Represents all the information needed to generate an OpenAPI path item
 * without performing any runtime reflection. Instances are constructed by
 * a build-time scanner that reads route registrations and attribute metadata.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class EndpointMetadata
{
    /**
     * @param string $path The route path pattern (e.g., '/api/users/{id}')
     * @param list<string> $methods HTTP methods (e.g., ['GET', 'POST'])
     * @param string|null $handlerClass Fully qualified handler class name
     * @param string|null $handlerMethod Handler method name
     * @param ApiDoc|null $doc Documentation attribute from the handler
     * @param list<ApiParam> $params Parameter attributes from the handler
     * @param list<ApiResponse> $responses Response attributes from the handler
     * @param list<string> $middleware Middleware applied to this endpoint
     * @param list<string> $securitySchemes Security scheme names for this endpoint
     */
    public function __construct(
        public string $path,
        public array $methods,
        public ?string $handlerClass = null,
        public ?string $handlerMethod = null,
        public ?ApiDoc $doc = null,
        public array $params = [],
        public array $responses = [],
        public array $middleware = [],
        public array $securitySchemes = [],
    ) {}
}
