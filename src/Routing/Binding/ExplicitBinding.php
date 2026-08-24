<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use Pulsar\Api\Internal;

/**
 * Represents a manually registered parameter-to-model binding.
 *
 * Registered via Router::model() and takes precedence over implicit
 * type-hint resolution during model binding.
 *
 * It also carries the one scope declaration an application can make from a
 * route file. Leaving {@see $scope} at {@see BindingScope::Path} says nothing
 * about scoping and leaves the route path in charge, which is what an explicit
 * binding registered to name a model or swap a resolver wants.
 */
#[Internal(reason: 'Wiring detail; use Router::model() to register bindings')]
final readonly class ExplicitBinding
{
    /**
     * @param class-string $modelClass
     * @param class-string|null $resolverClass
     * @param string|null $parentRelation Relation to resolve through; required by, and exclusive to, {@see BindingScope::Contained}
     */
    public function __construct(
        public string $parameter,
        public string $modelClass,
        public ?string $resolverClass = null,
        public BindingScope $scope = BindingScope::Path,
        public ?string $parentRelation = null,
    ) {
        if ($scope === BindingScope::Contained && ($parentRelation === null || $parentRelation === '')) {
            throw ModelBindingException::inconsistentScope(
                $modelClass,
                'a contained binding must name the relation it resolves through',
            );
        }

        if ($scope !== BindingScope::Contained && $parentRelation !== null) {
            throw ModelBindingException::inconsistentScope(
                $modelClass,
                'only a contained binding may name a relation',
            );
        }
    }
}
