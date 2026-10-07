<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Compiler\Pass;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Compiler\ContainerBuilder;
use Pulsar\Container\Compiler\Pass\ResolveTaggedIteratorPass;
use Pulsar\Container\ServiceDefinition;
use Pulsar\Container\Tag\TaggedIterator;
use Pulsar\Container\TagDefinition;
use stdClass;

#[CoversClass(ResolveTaggedIteratorPass::class)]
final class ResolveTaggedIteratorPassTest extends TestCase
{
    #[Test]
    public function processSkipsDefinitionsWithoutTaggedIterator(): void
    {
        $builder = new ContainerBuilder();
        $builder->setDefinition(stdClass::class, new ServiceDefinition(
            id: stdClass::class,
            concrete: stdClass::class,
        ));

        $pass = new ResolveTaggedIteratorPass();
        $pass->process($builder);

        $def = $builder->getDefinition(stdClass::class);
        self::assertNotNull($def);
        // Should remain unchanged — still a string concrete
        self::assertIsString($def->concrete);
    }

    #[Test]
    public function processSkipsCallableDefinitions(): void
    {
        $builder = new ContainerBuilder();
        $builder->setDefinition('callable.svc', new ServiceDefinition(
            id: 'callable.svc',
            concrete: fn() => new stdClass(),
        ));

        $pass = new ResolveTaggedIteratorPass();
        $pass->process($builder);

        $def = $builder->getDefinition('callable.svc');
        self::assertNotNull($def);
    }

    #[Test]
    public function processRewritesDefinitionWithTaggedIteratorParam(): void
    {
        $builder = new ContainerBuilder();

        // Register a tagged service
        $builder->setDefinition('handler1', new ServiceDefinition(
            id: 'handler1',
            concrete: stdClass::class,
            tags: [new TagDefinition('handler', 0)],
        ));

        // Register the consumer that uses #[TaggedIterator]
        $builder->setDefinition(TaggedConsumerStub::class, new ServiceDefinition(
            id: TaggedConsumerStub::class,
            concrete: TaggedConsumerStub::class,
        ));

        $pass = new ResolveTaggedIteratorPass();
        $pass->process($builder);

        $def = $builder->getDefinition(TaggedConsumerStub::class);
        self::assertNotNull($def);
        // The concrete should have been rewritten to a Closure
        self::assertInstanceOf(Closure::class, $def->concrete);
    }

    #[Test]
    public function processSkipsDefinitionsWithNoConstructor(): void
    {
        $builder = new ContainerBuilder();
        $builder->setDefinition(stdClass::class, new ServiceDefinition(
            id: stdClass::class,
            concrete: stdClass::class,
        ));

        $pass = new ResolveTaggedIteratorPass();
        $pass->process($builder);

        $def = $builder->getDefinition(stdClass::class);
        self::assertNotNull($def);
        self::assertIsString($def->concrete);
    }
}

class TaggedConsumerStub
{
    /** @param list<object> $handlers */
    public function __construct(
        #[TaggedIterator('handler')]
        public readonly array $handlers,
    ) {}
}
