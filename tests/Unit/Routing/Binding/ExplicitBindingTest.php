<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use ArrayObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Routing\Binding\ExplicitBinding;
use ReflectionClass;
use stdClass;

#[CoversClass(ExplicitBinding::class)]
final class ExplicitBindingTest extends TestCase
{
    #[Test]
    public function constructWithRequiredParameters(): void
    {
        $binding = new ExplicitBinding(
            parameter: 'user',
            modelClass: stdClass::class,
        );

        self::assertSame('user', $binding->parameter);
        self::assertSame(stdClass::class, $binding->modelClass);
        self::assertNull($binding->resolverClass);
    }

    #[Test]
    public function constructWithCustomResolver(): void
    {
        $binding = new ExplicitBinding(
            parameter: 'post',
            modelClass: stdClass::class,
            resolverClass: ArrayObject::class,
        );

        self::assertSame('post', $binding->parameter);
        self::assertSame(stdClass::class, $binding->modelClass);
        self::assertSame(ArrayObject::class, $binding->resolverClass);
    }

    #[Test]
    public function isReadonlyClass(): void
    {
        $ref = new ReflectionClass(ExplicitBinding::class);

        self::assertTrue($ref->isReadonly());
    }
}
