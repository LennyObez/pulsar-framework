<?php

declare(strict_types=1);

namespace Pulsar\Api\OpenApi\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Documents a response for an API endpoint.
 *
 * Applied to resource class methods to describe the response status codes,
 * descriptions, and schema references in the generated OpenAPI specification.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
#[Api(since: '1.0.0')]
final readonly class ApiResponse
{
    /**
     * @param int $status HTTP status code (e.g., 200, 201, 404)
     * @param string $description Human-readable description of the response
     * @param class-string|null $schema DTO or resource class to infer the response schema from
     * @param bool $isCollection Whether the response is a collection of the schema type
     * @param string $mediaType Response media type
     * @param array<string, string>|null $headers Response headers as name => description
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public int $status = 200,
        public string $description = '',
        public ?string $schema = null,
        public bool $isCollection = false,
        public string $mediaType = 'application/json',
        public ?array $headers = null,
    ) {}
}
