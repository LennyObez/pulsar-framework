<?php

declare(strict_types=1);

namespace Pulsar\Api\OpenApi\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Documents an API endpoint for OpenAPI spec generation.
 *
 * Applied to resource class methods to provide human-readable documentation
 * that is emitted into the OpenAPI specification at build time.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class ApiDoc
{
    /**
     * @param string $summary Short one-line summary of the endpoint
     * @param string $description Extended description (supports CommonMark)
     * @param list<string> $tags Logical grouping tags for the endpoint
     * @param bool $deprecated Whether this endpoint is deprecated
     * @param string|null $operationId Explicit operation ID (auto-generated if null)
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $summary = '',
        public string $description = '',
        public array $tags = [],
        public bool $deprecated = false,
        public ?string $operationId = null,
    ) {}
}
