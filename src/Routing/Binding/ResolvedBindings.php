<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use Pulsar\Api\Internal;

/**
 * Result of resolving a route's model bindings.
 *
 * Pairs each resolved model with the {@see BindingMeta} that produced it,
 * keyed by route-parameter name. The metadata carries the per-parameter
 * authorization policy declared for the binding, which the model-binding
 * middleware needs to enforce the correct permission rather than a blanket
 * default. Both maps share the same keys.
 */
#[Internal(reason: 'Return type of ModelBinder::bindWithMeta(); consumed by the model-binding middleware')]
final readonly class ResolvedBindings
{
    /**
     * @param array<string, object>      $models Resolved models keyed by parameter name
     * @param array<string, BindingMeta> $metas  Binding metadata keyed by parameter name
     */
    public function __construct(
        public array $models,
        public array $metas,
    ) {}
}
