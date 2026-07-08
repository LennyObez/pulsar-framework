<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Config\AdminRateLimitConfig;
use Pulsar\Extension\Admin\Internal\Middleware\AdminRateLimitMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(AdminRateLimitMiddleware::class)]
final class AdminRateLimitMiddlewareTest extends TestCase
{
    #[Test]
    public function canBeConstructed(): void
    {
        $middleware = new AdminRateLimitMiddleware($this->config());

        self::assertInstanceOf(AdminRateLimitMiddleware::class, $middleware);
    }

    /**
     * The configured read limit is the limit actually applied — proving the
     * per-category config is read rather than ignored in favour of a single
     * shared allowance.
     */
    #[Test]
    public function appliesTheConfiguredReadLimit(): void
    {
        $middleware = new AdminRateLimitMiddleware(
            AdminRateLimitConfig::fromArray(['read_limit' => 7, 'write_limit' => 3, 'export_limit' => 2, 'window_seconds' => 60]),
        );

        $response = $middleware->process(new ServerRequest(method: 'GET', uri: '/admin/dashboard'), $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('7', $response->getHeaderLine('X-RateLimit-Limit'));
    }

    /**
     * The write limit is enforced independently: once the (small) write
     * budget is exhausted, further writes are throttled. The original bug
     * was that the write limit had no effect at all.
     */
    #[Test]
    public function enforcesTheWriteLimitOnceExhausted(): void
    {
        $middleware = new AdminRateLimitMiddleware(
            AdminRateLimitConfig::fromArray(['read_limit' => 100, 'write_limit' => 1, 'export_limit' => 1, 'window_seconds' => 60]),
        );

        $first = $middleware->process(new ServerRequest(method: 'POST', uri: '/admin/resources/users'), $this->okHandler());
        $second = $middleware->process(new ServerRequest(method: 'POST', uri: '/admin/resources/users'), $this->okHandler());

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
        self::assertNotSame('', $second->getHeaderLine('Retry-After'));
    }

    /**
     * Read, write, and export are counted in separate buckets: a saturated
     * write bucket must not throttle reads, and vice versa.
     */
    #[Test]
    public function categoriesAreIsolatedFromEachOther(): void
    {
        $middleware = new AdminRateLimitMiddleware(
            AdminRateLimitConfig::fromArray(['read_limit' => 100, 'write_limit' => 1, 'export_limit' => 1, 'window_seconds' => 60]),
        );

        // Exhaust the write bucket.
        $middleware->process(new ServerRequest(method: 'POST', uri: '/admin/resources/users'), $this->okHandler());
        $blockedWrite = $middleware->process(new ServerRequest(method: 'POST', uri: '/admin/resources/users'), $this->okHandler());

        // Reads still pass despite the saturated write bucket.
        $read = $middleware->process(new ServerRequest(method: 'GET', uri: '/admin/dashboard'), $this->okHandler());

        self::assertSame(429, $blockedWrite->getStatusCode());
        self::assertSame(200, $read->getStatusCode());
    }

    /**
     * Export uses its own (typically stricter) limit, keyed on the `/export`
     * path segment even for GET requests.
     */
    #[Test]
    public function exportUsesItsOwnLimit(): void
    {
        $middleware = new AdminRateLimitMiddleware(
            AdminRateLimitConfig::fromArray(['read_limit' => 100, 'write_limit' => 100, 'export_limit' => 1, 'window_seconds' => 60]),
        );

        $first = $middleware->process(new ServerRequest(method: 'GET', uri: '/admin/resources/users/export'), $this->okHandler());
        $second = $middleware->process(new ServerRequest(method: 'GET', uri: '/admin/resources/users/export'), $this->okHandler());

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
    }

    /**
     * Each authenticated actor is throttled independently, keyed on identity,
     * so one admin saturating their budget never throttles another.
     */
    #[Test]
    public function eachIdentityIsThrottledIndependently(): void
    {
        $middleware = new AdminRateLimitMiddleware(
            AdminRateLimitConfig::fromArray(['read_limit' => 1, 'write_limit' => 1, 'export_limit' => 1, 'window_seconds' => 60]),
        );

        $alice = new ServerRequest(method: 'GET', uri: '/admin/dashboard', attributes: ['identity' => $this->identity('alice')]);
        $bob = new ServerRequest(method: 'GET', uri: '/admin/dashboard', attributes: ['identity' => $this->identity('bob')]);

        self::assertSame(200, $middleware->process($alice, $this->okHandler())->getStatusCode());
        self::assertSame(429, $middleware->process($alice, $this->okHandler())->getStatusCode());
        self::assertSame(200, $middleware->process($bob, $this->okHandler())->getStatusCode(), 'bob keeps his own budget');
    }

    private function config(): AdminRateLimitConfig
    {
        return AdminRateLimitConfig::fromArray([]);
    }

    private function identity(string $id): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn($id);

        return $identity;
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::text('OK');
            }
        };
    }
}
