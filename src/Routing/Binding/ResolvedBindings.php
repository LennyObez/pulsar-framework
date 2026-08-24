<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use Pulsar\Api\Internal;

/**
 * Result of resolving a route's model bindings.
 *
 * Pairs each resolved model with the {@see BindingMeta} that produced it,
 * keyed by route-parameter name. Both maps share the same keys.
 *
 * The metadata travels with the models because it is the declaration behind
 * each one — the key it was looked up by, the scope it was resolved under and
 * the authorization policy declared for it — and reading it back off the result
 * costs nothing, where recovering it means repeating the reflection or
 * compiled-map lookup that produced it. Enforcement no longer reads it from
 * here: {@see ModelBinder::bindWithMeta()} hands each meta to its authorization
 * gate as that level resolves, which is what keeps a refusal from arriving a
 * pass too late to stop the next lookup.
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
