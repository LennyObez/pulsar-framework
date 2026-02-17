<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Routing\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Routing\Binding\ExplicitBinding;
use Pulsar\Routing\Router;
use stdClass;

#[CoversClass(Router::class)]
#[CoversClass(ExplicitBinding::class)]
final class RouterModelBindingTest extends TestCase
{
    #[Test]
    public function modelRegistersExplicitBinding(): void
    {
        $router = new Router();

        $router->model('user', stdClass::class);

        $bindings = $router->getExplicitBindings();

        self::assertCount(1, $bindings);
        self::assertSame('user', $bindings[0]->parameter);
        self::assertSame(stdClass::class, $bindings[0]->modelClass);
        self::assertNull($bindings[0]->resolverClass);
    }

    #[Test]
    public function modelRegistersExplicitBindingWithResolver(): void
    {
        $router = new Router();

        $router->model('user', stdClass::class, stdClass::class);

        $bindings = $router->getExplicitBindings();

        self::assertCount(1, $bindings);
        self::assertSame(stdClass::class, $bindings[0]->resolverClass);
    }

    #[Test]
    public function getExplicitBindingsReturnsAllRegistered(): void
    {
        $router = new Router();

        $router->model('user', stdClass::class);
        $router->model('post', stdClass::class, stdClass::class);
        $router->model('comment', stdClass::class);

        $bindings = $router->getExplicitBindings();

        self::assertCount(3, $bindings);
        self::assertSame('user', $bindings[0]->parameter);
        self::assertSame('post', $bindings[1]->parameter);
        self::assertSame('comment', $bindings[2]->parameter);
    }

    #[Test]
    public function modelReturnsSelfForChaining(): void
    {
        $router = new Router();

        $result = $router->model('user', stdClass::class);

        self::assertSame($router, $result);
    }

    #[Test]
    public function getExplicitBindingsReturnsEmptyByDefault(): void
    {
        $router = new Router();

        self::assertSame([], $router->getExplicitBindings());
    }
}
