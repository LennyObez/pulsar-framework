<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\PolicyInterface;
use Pulsar\Extension\Admin\Config\AdminSchemaConfig;
use Pulsar\Extension\Admin\Internal\Middleware\AdminSchemaMiddleware;

#[CoversClass(AdminSchemaMiddleware::class)]
final class AdminSchemaMiddlewareTest extends TestCase
{
    #[Test]
    public function can_be_constructed(): void
    {
        $config = AdminSchemaConfig::fromArray(['enabled' => true]);
        $policy = $this->createStub(PolicyInterface::class);

        $middleware = new AdminSchemaMiddleware($config, $policy);

        self::assertInstanceOf(AdminSchemaMiddleware::class, $middleware);
    }
}
