<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Internal\Middleware\AdminCspMiddleware;

#[CoversClass(AdminCspMiddleware::class)]
final class AdminCspMiddlewareTest extends TestCase
{
    #[Test]
    public function can_be_constructed(): void
    {
        $config = AdminConfig::fromArray(['enabled' => true]);

        $middleware = new AdminCspMiddleware($config);

        self::assertInstanceOf(AdminCspMiddleware::class, $middleware);
    }
}
