<?php

declare(strict_types=1);

namespace Pulsar\Api\OpenApi\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Documents a parameter for an API endpoint.
 *
 * Applied to resource class methods to describe path, query, or header
 * parameters in the generated OpenAPI specification.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
#[Api(since: '1.0.0')]
final readonly class ApiParam
{
    /**
     * @param string $name Parameter name as it appears in the request
     * @param string $in Parameter location: 'path', 'query', 'header', or 'cookie'
     * @param string $type JSON Schema type: 'string', 'integer', 'number', 'boolean', 'array'
     * @param bool $required Whether the parameter is required
     * @param string $description Human-readable description
     * @param string|null $format JSON Schema format (e.g., 'uuid', 'date-time', 'email')
     * @param mixed $example Example value for documentation
     * @param mixed $default Default value when not provided
     * @param list<string|int|float>|null $enum Allowed values
     */
    public function __construct(
        public string $name,
        public string $in = 'query',
        public string $type = 'string',
        public bool $required = false,
        public string $description = '',
        public ?string $format = null,
        public mixed $example = null,
        public mixed $default = null,
        public ?array $enum = null,
    ) {}
}
