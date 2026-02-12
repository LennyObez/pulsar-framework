<?php

declare(strict_types=1);

namespace Pulsar\Container\Compiler\Pass;

use Pulsar\Api\Internal;
use Pulsar\Container\Compiler\CompilerPassInterface;
use Pulsar\Container\Compiler\ContainerBuilder;
use Pulsar\Container\Decorator\Decorate;
use Pulsar\Container\DecoratorDefinition;
use Pulsar\Container\Lazy\Lazy;
use Pulsar\Container\Tag\Tag;
use Pulsar\Container\TagDefinition;
use ReflectionClass;

use function class_exists;
use function is_string;

/**
 * Scans concrete classes for #[Tag], #[Lazy], and #[Decorate] attributes.
 *
 * Populates ServiceDefinition tags, lazy flag, and decorators from
 * attribute metadata on the concrete class.
 */
#[Internal]
final class AutoTagPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $builder): void
    {
        foreach ($builder->allDefinitions() as $id => $definition) {
            $concrete = $definition->concrete;

            if (!is_string($concrete) || !class_exists($concrete)) {
                continue;
            }

            $reflector = new ReflectionClass($concrete);

            // Scan #[Tag] attributes
            $tagAttributes = $reflector->getAttributes(Tag::class);
            $newTags = [];
            foreach ($tagAttributes as $attr) {
                /** @var Tag $tag */
                $tag = $attr->newInstance();
                $newTags[] = new TagDefinition($tag->name, $tag->priority, $tag->attributes);
            }

            if ($newTags !== []) {
                $definition = $definition->withTags(...$newTags);
            }

            // Scan #[Lazy] attribute — PHP 8.4+ newLazyProxy() works with final classes
            $lazyAttributes = $reflector->getAttributes(Lazy::class);
            if ($lazyAttributes !== []) {
                $definition = $definition->withLazy();
            }

            // Scan #[Decorate] attribute
            $decorateAttributes = $reflector->getAttributes(Decorate::class);
            foreach ($decorateAttributes as $attr) {
                /** @var Decorate $decorate */
                $decorate = $attr->newInstance();

                $targetDef = $builder->getDefinition($decorate->decorates);
                if ($targetDef !== null) {
                    $builder->setDefinition(
                        $decorate->decorates,
                        $targetDef->withDecorators(
                            new DecoratorDefinition($concrete, $decorate->priority),
                        ),
                    );
                }
            }

            $builder->setDefinition($id, $definition);
        }
    }
}
