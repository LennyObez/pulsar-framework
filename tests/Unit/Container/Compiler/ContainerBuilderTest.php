<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Compiler\ContainerBuilder;
use Pulsar\Container\ServiceDefinition;
use Pulsar\Container\TagDefinition;
use stdClass;

#[CoversClass(ContainerBuilder::class)]
final class ContainerBuilderTest extends TestCase
{
    #[Test]
    public function setAndGetDefinition(): void
    {
        $builder = new ContainerBuilder();
        $def = new ServiceDefinition(id: 'foo', concrete: stdClass::class);

        $builder->setDefinition('foo', $def);

        self::assertSame($def, $builder->getDefinition('foo'));
    }

    #[Test]
    public function getDefinitionReturnsNullForMissing(): void
    {
        $builder = new ContainerBuilder();

        self::assertNull($builder->getDefinition('nonexistent'));
    }

    #[Test]
    public function removeDefinitionRemovesIt(): void
    {
        $builder = new ContainerBuilder();
        $builder->setDefinition('foo', new ServiceDefinition(id: 'foo', concrete: stdClass::class));

        $builder->removeDefinition('foo');

        self::assertFalse($builder->hasDefinition('foo'));
    }

    #[Test]
    public function allDefinitionsAreSortedByServiceId(): void
    {
        $builder = new ContainerBuilder();
        $builder->setDefinition('z', new ServiceDefinition(id: 'z', concrete: stdClass::class));
        $builder->setDefinition('a', new ServiceDefinition(id: 'a', concrete: stdClass::class));
        $builder->setDefinition('m', new ServiceDefinition(id: 'm', concrete: stdClass::class));

        $keys = array_keys($builder->allDefinitions());

        self::assertSame(['a', 'm', 'z'], $keys);
    }

    #[Test]
    public function findTaggedServiceIdsReturnsSortedIds(): void
    {
        $builder = new ContainerBuilder();
        $builder->setDefinition('b', new ServiceDefinition(
            id: 'b',
            concrete: stdClass::class,
            tags: [new TagDefinition('tag', 10)],
        ));
        $builder->setDefinition('a', new ServiceDefinition(
            id: 'a',
            concrete: stdClass::class,
            tags: [new TagDefinition('tag', 20)],
        ));

        $ids = $builder->findTaggedServiceIds('tag');

        self::assertSame(['a', 'b'], $ids); // 'a' has priority 20, 'b' has 10
    }

    #[Test]
    public function getServiceIdsReturnsSortedList(): void
    {
        $builder = new ContainerBuilder();
        $builder->setDefinition('z', new ServiceDefinition(id: 'z', concrete: stdClass::class));
        $builder->setDefinition('a', new ServiceDefinition(id: 'a', concrete: stdClass::class));

        self::assertSame(['a', 'z'], $builder->getServiceIds());
    }
}
