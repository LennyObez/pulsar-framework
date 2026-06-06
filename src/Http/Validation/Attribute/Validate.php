<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Declares validation rules on a DTO property.
 *
 * Used by ValidationCompiler to extract rules at build time.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
#[Api(since: '1.0.0')]
final readonly class Validate
{
    /**
     * @param class-string $rule Fully qualified rule class name
     * @param array<string, mixed> $parameters Constructor parameters for the rule
     * @param list<string> $groups Groups this rule belongs to (empty = all groups)
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $rule,
        public array $parameters = [],
        public array $groups = [],
    ) {}
}
