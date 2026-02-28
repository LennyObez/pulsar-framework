<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use Pulsar\Api\Internal;

/**
 * Represents a manually registered parameter-to-model binding.
 *
 * Registered via Router::model() and takes precedence over implicit
 * type-hint resolution during model binding.
 */
#[Internal(reason: 'Wiring detail; use Router::model() to register bindings')]
readonly class ExplicitBinding
{
    /**
     * @param class-string $modelClass
     * @param class-string|null $resolverClass
     */
    public function __construct(
        public string $parameter,
        public string $modelClass,
        public ?string $resolverClass = null,
    ) {}
}
