<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use Pulsar\Api\Api;

/**
 * Metadata describing how a single route parameter should be bound to a model.
 *
 * Captures the model class, lookup key, scoping, authorization policy,
 * and optional custom resolver for a specific route parameter.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final readonly class BindingMeta
{
    /**
     * @param class-string $class
     * @param class-string|null $customResolver
     */
    public function __construct(
        public string $class,
        public string $keyName = 'id',
        public string $keyType = 'int',
        public bool $scoped = false,
        public ?string $parentRelation = null,
        public ?string $authzPolicy = null,
        public ?string $customResolver = null,
    ) {}
}
