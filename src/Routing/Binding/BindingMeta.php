<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use Pulsar\Api\Api;

/**
 * Metadata describing how a single route parameter should be bound to a model.
 *
 * Captures the model class, lookup key, scoping, authorization policy,
 * and optional custom resolver for a specific route parameter.
 *
 * ## Scope
 *
 * {@see $scope} is the whole of the scoping decision, and the three fields
 * that describe it move together:
 *
 * | `$scope`     | `$parentRelation` | `$parentParameter` | meaning                        |
 * | ------------ | ----------------- | ------------------ | ------------------------------ |
 * | `Path`       | null              | null               | undecided: the path will decide |
 * | `Contained`  | required          | set once decided   | resolve through the parent      |
 * | `Root`       | null              | null               | resolve globally, deliberately  |
 *
 * Any other combination is rejected by the constructor rather than left to be
 * interpreted downstream, because the interpretations differ in whether a
 * request reads another tenant's row. {@see $scoped} is derived from `$scope`
 * and exists so consumers can ask the question they actually have.
 *
 * A {@see BindingResolver} never returns `Path`: what it hands to
 * {@see ModelBinder} is always a decided `Contained` or `Root`.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final readonly class BindingMeta
{
    /**
     * Whether this parameter resolves through its parent rather than globally.
     *
     * Derived from {@see $scope}: the two cannot disagree.
     */
    public bool $scoped;

    /**
     * @param class-string $class
     * @param class-string|null $customResolver
     * @param string|null $parentRelation Relation on the parent to resolve through; required by, and exclusive to, {@see BindingScope::Contained}
     * @param string|null $parentParameter Route parameter holding the parent model; filled in by {@see BindingResolver} from the path
     */
    public function __construct(
        public string $class,
        public string $keyName = 'id',
        public string $keyType = 'int',
        public BindingScope $scope = BindingScope::Path,
        public ?string $parentRelation = null,
        public ?string $authzPolicy = null,
        public ?string $customResolver = null,
        public ?string $parentParameter = null,
    ) {
        if ($scope === BindingScope::Contained) {
            if ($parentRelation === null || $parentRelation === '') {
                throw ModelBindingException::inconsistentScope(
                    $class,
                    'a contained binding must name the relation it resolves through',
                );
            }
        } elseif ($parentRelation !== null || $parentParameter !== null) {
            throw ModelBindingException::inconsistentScope(
                $class,
                'only a contained binding may carry a parent',
            );
        }

        $this->scoped = $scope === BindingScope::Contained;
    }
}
