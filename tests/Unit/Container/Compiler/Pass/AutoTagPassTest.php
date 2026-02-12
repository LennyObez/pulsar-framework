<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Compiler\Pass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Compiler\ContainerBuilder;
use Pulsar\Container\Compiler\Pass\AutoTagPass;
use Pulsar\Container\Lazy\Lazy;
use Pulsar\Container\ServiceDefinition;
use Pulsar\Container\Tag\Tag;

#[CoversClass(AutoTagPass::class)]
final class AutoTagPassTest extends TestCase
{
    #[Test]
    public function scansTagAttributes(): void
    {
        $builder = new ContainerBuilder();
        $builder->setDefinition(
            TaggedServiceStub::class,
            new ServiceDefinition(id: TaggedServiceStub::class, concrete: TaggedServiceStub::class),
        );

        $pass = new AutoTagPass();
        $pass->process($builder);

        $def = $builder->getDefinition(TaggedServiceStub::class);

        self::assertNotNull($def);
        self::assertCount(1, $def->tags);
        self::assertSame('event.listener', $def->tags[0]->name);
        self::assertSame(10, $def->tags[0]->priority);
    }

    #[Test]
    public function scansLazyAttribute(): void
    {
        $builder = new ContainerBuilder();
        $builder->setDefinition(
            LazyServiceStub::class,
            new ServiceDefinition(id: LazyServiceStub::class, concrete: LazyServiceStub::class),
        );

        $pass = new AutoTagPass();
        $pass->process($builder);

        $def = $builder->getDefinition(LazyServiceStub::class);

        self::assertNotNull($def);
        self::assertTrue($def->lazy);
    }

    #[Test]
    public function allowsLazyOnFinalClass(): void
    {
        $builder = new ContainerBuilder();
        $builder->setDefinition(
            FinalLazyServiceStub::class,
            new ServiceDefinition(id: FinalLazyServiceStub::class, concrete: FinalLazyServiceStub::class),
        );

        $pass = new AutoTagPass();
        $pass->process($builder);

        $def = $builder->getDefinition(FinalLazyServiceStub::class);

        // PHP 8.4+ newLazyProxy() works with final classes
        self::assertNotNull($def);
        self::assertTrue($def->lazy);
    }
}

#[Tag('event.listener', priority: 10)]
class TaggedServiceStub {}

#[Lazy]
class LazyServiceStub {}

#[Lazy]
final class FinalLazyServiceStub {}
