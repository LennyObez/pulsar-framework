<?php

declare(strict_types=1);

namespace Pulsar\Container;

use Closure;
use NoDiscard;
use Pulsar\Api\Internal;

use function array_values;

/**
 * Immutable service definition capturing all metadata for a container binding.
 *
 * Replaces the internal `array{concrete, type}` representation with a rich
 * DTO that supports tags, decorators, lazy proxies, and contextual bindings.
 */
#[Internal]
final readonly class ServiceDefinition
{
    /**
     * @param string $id Service identifier (interface or class FQCN)
     * @param Closure|class-string $concrete Factory closure or class name
     * @param Lifetime $lifetime How long the resolved instance lives
     * @param list<TagDefinition> $tags Tags attached to this service
     * @param bool $lazy Whether to wrap in a lazy proxy
     * @param list<DecoratorDefinition> $decorators Decorator chain
     * @param string|null $contextFor Consumer class this binding is specific to (contextual binding)
     */
    public function __construct(
        public string $id,
        public Closure|string $concrete,
        public Lifetime $lifetime = Lifetime::Singleton,
        public array $tags = [],
        public bool $lazy = false,
        public array $decorators = [],
        public ?string $contextFor = null,
    ) {}

    /**
     * Create a new definition with additional tags appended.
     *
     * @param TagDefinition ...$newTags Tags to append
     */
    #[NoDiscard]
    public function withTags(TagDefinition ...$newTags): self
    {
        return new self(
            id: $this->id,
            concrete: $this->concrete,
            lifetime: $this->lifetime,
            tags: array_values([...$this->tags, ...$newTags]),
            lazy: $this->lazy,
            decorators: $this->decorators,
            contextFor: $this->contextFor,
        );
    }

    /**
     * Create a new definition with additional decorators appended.
     *
     * @param DecoratorDefinition ...$newDecorators Decorators to append
     */
    #[NoDiscard]
    public function withDecorators(DecoratorDefinition ...$newDecorators): self
    {
        return new self(
            id: $this->id,
            concrete: $this->concrete,
            lifetime: $this->lifetime,
            tags: $this->tags,
            lazy: $this->lazy,
            decorators: array_values([...$this->decorators, ...$newDecorators]),
            contextFor: $this->contextFor,
        );
    }

    /**
     * Create a new definition with the lazy flag set.
     */
    #[NoDiscard]
    public function withLazy(bool $lazy = true): self
    {
        return new self(
            id: $this->id,
            concrete: $this->concrete,
            lifetime: $this->lifetime,
            tags: $this->tags,
            lazy: $lazy,
            decorators: $this->decorators,
            contextFor: $this->contextFor,
        );
    }
}
