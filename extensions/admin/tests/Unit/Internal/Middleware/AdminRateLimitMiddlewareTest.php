<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Internal\Middleware\AdminRateLimitMiddleware;
use Pulsar\Http\RateLimit\RateLimiterInterface;

#[CoversClass(AdminRateLimitMiddleware::class)]
final class AdminRateLimitMiddlewareTest extends TestCase
{
    #[Test]
    public function can_be_constructed(): void
    {
        $rateLimiter = $this->createStub(RateLimiterInterface::class);

        $middleware = new AdminRateLimitMiddleware($rateLimiter);

        self::assertInstanceOf(AdminRateLimitMiddleware::class, $middleware);
    }
}
