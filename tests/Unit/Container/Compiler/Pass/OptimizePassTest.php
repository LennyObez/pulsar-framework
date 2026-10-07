<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Compiler\Pass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Compiler\ContainerBuilder;
use Pulsar\Container\Compiler\Pass\OptimizePass;
use Pulsar\Container\ServiceDefinition;
use stdClass;

#[CoversClass(OptimizePass::class)]
final class OptimizePassTest extends TestCase
{
    #[Test]
    public function processGeneratesHintsForClassWithDependencies(): void
    {
        $builder = new ContainerBuilder();
        $builder->setDefinition(OptDepStub::class, new ServiceDefinition(
            id: OptDepStub::class,
            concrete: OptDepStub::class,
        ));
        $builder->setDefinition(OptConsumerStub::class, new ServiceDefinition(
            id: OptConsumerStub::class,
            concrete: OptConsumerStub::class,
        ));

        $pass = new OptimizePass();
        $pass->process($builder);

        self::assertArrayHasKey(OptConsumerStub::class, $pass->hints);
        self::assertCount(1, $pass->hints[OptConsumerStub::class]);
        self::assertSame('dep', $pass->hints[OptConsumerStub::class][0]['name']);
        self::assertSame(OptDepStub::class, $pass->hints[OptConsumerStub::class][0]['type']);
    }

    #[Test]
    public function processSkipsClassWithNoConstructor(): void
    {
        $builder = new ContainerBuilder();
        $builder->setDefinition(stdClass::class, new ServiceDefinition(
            id: stdClass::class,
            concrete: stdClass::class,
        ));

        $pass = new OptimizePass();
        $pass->process($builder);

        self::assertArrayNotHasKey(stdClass::class, $pass->hints);
    }

    #[Test]
    public function processSkipsCallableDefinitions(): void
    {
        $builder = new ContainerBuilder();
        $builder->setDefinition('callable.svc', new ServiceDefinition(
            id: 'callable.svc',
            concrete: fn() => new stdClass(),
        ));

        $pass = new OptimizePass();
        $pass->process($builder);

        self::assertEmpty($pass->hints);
    }

    #[Test]
    public function processSkipsClassWithBuiltinParameter(): void
    {
        $builder = new ContainerBuilder();
        $builder->setDefinition(OptBuiltinParamStub::class, new ServiceDefinition(
            id: OptBuiltinParamStub::class,
            concrete: OptBuiltinParamStub::class,
        ));

        $pass = new OptimizePass();
        $pass->process($builder);

        self::assertArrayNotHasKey(OptBuiltinParamStub::class, $pass->hints);
    }

    #[Test]
    public function processSkipsClassWithVariadicParameter(): void
    {
        $builder = new ContainerBuilder();
        $builder->setDefinition(OptVariadicStub::class, new ServiceDefinition(
            id: OptVariadicStub::class,
            concrete: OptVariadicStub::class,
        ));

        $pass = new OptimizePass();
        $pass->process($builder);

        self::assertArrayNotHasKey(OptVariadicStub::class, $pass->hints);
    }

    #[Test]
    public function processIncludesSelfTypedParameterAsResolvedClassName(): void
    {
        $builder = new ContainerBuilder();
        $builder->setDefinition(OptSelfRefStub::class, new ServiceDefinition(
            id: OptSelfRefStub::class,
            concrete: OptSelfRefStub::class,
        ));

        $pass = new OptimizePass();
        $pass->process($builder);

        // PHP 8.5 resolves `self` to the actual class name in reflection,
        // so OptimizePass treats it as a normal class dependency
        self::assertArrayHasKey(OptSelfRefStub::class, $pass->hints);
        self::assertSame(OptSelfRefStub::class, $pass->hints[OptSelfRefStub::class][0]['type']);
    }
}

class OptDepStub {}

class OptConsumerStub
{
    public function __construct(
        public readonly OptDepStub $dep,
    ) {}
}

class OptBuiltinParamStub
{
    public function __construct(
        public readonly int $count,
    ) {}
}

class OptVariadicStub
{
    /** @var list<OptDepStub> */
    public array $deps;

    public function __construct(OptDepStub ...$deps)
    {
        $this->deps = array_values($deps);
    }
}

class OptSelfRefStub
{
    public function __construct(
        public readonly self $parent,
    ) {}
}
