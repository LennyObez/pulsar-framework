<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Compiler\Pass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Compiler\ContainerBuilder;
use Pulsar\Container\Compiler\Pass\ValidateDecoratorPass;
use Pulsar\Container\DecoratorDefinition;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\ServiceDefinition;
use stdClass;

#[CoversClass(ValidateDecoratorPass::class)]
final class ValidateDecoratorPassTest extends TestCase
{
    #[Test]
    public function processPassesWithNoDecorators(): void
    {
        $this->expectNotToPerformAssertions();

        $builder = new ContainerBuilder();
        $builder->setDefinition('svc', new ServiceDefinition(
            id: 'svc',
            concrete: stdClass::class,
        ));

        $pass = new ValidateDecoratorPass();
        $pass->process($builder);
    }

    #[Test]
    public function processPassesWithValidDecoratorClass(): void
    {
        $this->expectNotToPerformAssertions();

        $builder = new ContainerBuilder();
        $builder->setDefinition('svc', new ServiceDefinition(
            id: 'svc',
            concrete: stdClass::class,
            decorators: [new DecoratorDefinition(stdClass::class, 0)],
        ));

        $pass = new ValidateDecoratorPass();
        $pass->process($builder);
    }

    #[Test]
    public function processPassesWithCallableDecorator(): void
    {
        $this->expectNotToPerformAssertions();

        $builder = new ContainerBuilder();
        $builder->setDefinition('svc', new ServiceDefinition(
            id: 'svc',
            concrete: stdClass::class,
            decorators: [new DecoratorDefinition(fn($inner, $c) => $inner, 0)],
        ));

        $pass = new ValidateDecoratorPass();
        $pass->process($builder);
    }

    #[Test]
    public function processThrowsForNonExistentDecoratorClass(): void
    {
        /** @var class-string $nonExistent */
        $nonExistent = trim('NonExistentDecoratorClass');

        $builder = new ContainerBuilder();
        $builder->setDefinition('svc', new ServiceDefinition(
            id: 'svc',
            concrete: stdClass::class,
            decorators: [new DecoratorDefinition($nonExistent, 0)],
        ));

        $pass = new ValidateDecoratorPass();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageIsOrContains('does not exist');

        $pass->process($builder);
    }
}
