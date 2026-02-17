<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Internal\Middleware\AdminCsrfMiddleware;

#[CoversClass(AdminCsrfMiddleware::class)]
final class AdminCsrfMiddlewareTest extends TestCase
{
    #[Test]
    public function can_be_constructed(): void
    {
        $config = AdminConfig::fromArray(['enabled' => true]);

        $middleware = new AdminCsrfMiddleware($config);

        self::assertInstanceOf(AdminCsrfMiddleware::class, $middleware);
    }
}
