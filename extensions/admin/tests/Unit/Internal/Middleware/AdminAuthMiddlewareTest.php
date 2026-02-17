<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Internal\Middleware\AdminAuthMiddleware;
use Pulsar\Extension\Admin\Internal\Policy\AdminResourcePolicy;
use Pulsar\Extension\Admin\Internal\Security\AdminAccessGate;

#[CoversClass(AdminAuthMiddleware::class)]
final class AdminAuthMiddlewareTest extends TestCase
{
    #[Test]
    public function can_be_constructed(): void
    {
        $config = AdminConfig::fromArray(['enabled' => true]);
        $policy = new AdminResourcePolicy($config);
        $gate = new AdminAccessGate($policy);

        $middleware = new AdminAuthMiddleware($gate, $config);

        self::assertInstanceOf(AdminAuthMiddleware::class, $middleware);
    }
}
