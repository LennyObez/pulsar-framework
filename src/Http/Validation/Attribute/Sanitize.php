<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Declares sanitization filters on a DTO property.
 *
 * Used by ValidationCompiler to extract filter configuration at build time.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
#[Api(since: '1.0.0')]
final readonly class Sanitize
{
    /**
     * @param class-string $filter Fully qualified filter class name
     * @param array<string, mixed> $parameters Constructor parameters for the filter
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $filter,
        public array $parameters = [],
    ) {}
}
