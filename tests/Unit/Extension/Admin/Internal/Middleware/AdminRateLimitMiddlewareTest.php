<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Internal\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Internal\Middleware\AdminRateLimitMiddleware;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\RateLimit\RateLimiterInterface;
use Pulsar\Http\RateLimit\RateLimitResult;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

#[CoversClass(AdminRateLimitMiddleware::class)]
final class AdminRateLimitMiddlewareTest extends TestCase
{
    private RateLimiterInterface&Stub $rateLimiter;
    private AdminRateLimitMiddleware $middleware;

    protected function setUp(): void
    {
        $this->rateLimiter = $this->createStub(RateLimiterInterface::class);
        $this->middleware = new AdminRateLimitMiddleware($this->rateLimiter);
    }

    private static function makeRequest(Method $method = Method::GET, string $path = '/admin/users', ?IdentityInterface $identity = null): Request
    {
        $attributes = [];
        if ($identity !== null) {
            $attributes['identity'] = $identity;
        }

        return new Request(
            method: $method,
            uri: $path,
            path: $path,
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            attributes: $attributes,
        );
    }

    #[Test]
    public function allowsRequestWhenNotRateLimited(): void
    {
        $this->rateLimiter->method('hit')->willReturn(
            new RateLimitResult(allowed: true, limit: 120, remaining: 119, retryAfter: 0),
        );

        $next = static fn(Request $r): Response => new Response(body: 'ok');

        $response = $this->middleware->process(self::makeRequest(), $next);

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('120', $response->headers->first('X-RateLimit-Limit'));
        self::assertSame('119', $response->headers->first('X-RateLimit-Remaining'));
    }

    #[Test]
    public function rejectsRequestWhenRateLimited(): void
    {
        $this->rateLimiter->method('hit')->willReturn(
            new RateLimitResult(allowed: false, limit: 120, remaining: 0, retryAfter: 45),
        );

        $next = static fn(Request $r): Response => new Response(body: 'ok');

        $response = $this->middleware->process(self::makeRequest(), $next);

        self::assertSame(ResponseStatus::TooManyRequests, $response->status);
        self::assertSame('45', $response->headers->first('Retry-After'));
        self::assertStringContainsString('Rate limit exceeded', $response->body);
    }

    #[Test]
    public function categorizesGetAsRead(): void
    {
        /** @var RateLimiterInterface&MockObject $rateLimiter */
        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->expects($this->once())
            ->method('hit')
            ->with($this->stringContains('admin:read:'))
            ->willReturn(new RateLimitResult(allowed: true, limit: 120, remaining: 119, retryAfter: 0));

        $middleware = new AdminRateLimitMiddleware($rateLimiter);
        $next = static fn(Request $r): Response => new Response(body: 'ok');

        $middleware->process(self::makeRequest(Method::GET), $next);
    }

    #[Test]
    public function categorizesPostAsWrite(): void
    {
        /** @var RateLimiterInterface&MockObject $rateLimiter */
        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->expects($this->once())
            ->method('hit')
            ->with($this->stringContains('admin:write:'))
            ->willReturn(new RateLimitResult(allowed: true, limit: 30, remaining: 29, retryAfter: 0));

        $middleware = new AdminRateLimitMiddleware($rateLimiter);
        $next = static fn(Request $r): Response => new Response(body: 'ok');

        $middleware->process(self::makeRequest(Method::POST, '/admin/users'), $next);
    }

    #[Test]
    public function categorizesExportPathAsExport(): void
    {
        /** @var RateLimiterInterface&MockObject $rateLimiter */
        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->expects($this->once())
            ->method('hit')
            ->with($this->stringContains('admin:export:'))
            ->willReturn(new RateLimitResult(allowed: true, limit: 5, remaining: 4, retryAfter: 0));

        $middleware = new AdminRateLimitMiddleware($rateLimiter);
        $next = static fn(Request $r): Response => new Response(body: 'ok');

        $middleware->process(self::makeRequest(Method::GET, '/admin/users/export'), $next);
    }

    #[Test]
    public function usesIdentityIdWhenAvailable(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('user-42');

        /** @var RateLimiterInterface&MockObject $rateLimiter */
        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->expects($this->once())
            ->method('hit')
            ->with('admin:read:user-42')
            ->willReturn(new RateLimitResult(allowed: true, limit: 120, remaining: 119, retryAfter: 0));

        $middleware = new AdminRateLimitMiddleware($rateLimiter);
        $next = static fn(Request $r): Response => new Response(body: 'ok');

        $middleware->process(self::makeRequest(Method::GET, '/admin/users', $identity), $next);
    }

    #[Test]
    public function usesAnonymousWhenNoIdentity(): void
    {
        /** @var RateLimiterInterface&MockObject $rateLimiter */
        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->expects($this->once())
            ->method('hit')
            ->with('admin:read:anonymous')
            ->willReturn(new RateLimitResult(allowed: true, limit: 120, remaining: 119, retryAfter: 0));

        $middleware = new AdminRateLimitMiddleware($rateLimiter);
        $next = static fn(Request $r): Response => new Response(body: 'ok');

        $middleware->process(self::makeRequest(), $next);
    }

    #[Test]
    public function categorizesDeleteAsWrite(): void
    {
        /** @var RateLimiterInterface&MockObject $rateLimiter */
        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->expects($this->once())
            ->method('hit')
            ->with($this->stringContains('admin:write:'))
            ->willReturn(new RateLimitResult(allowed: true, limit: 30, remaining: 29, retryAfter: 0));

        $middleware = new AdminRateLimitMiddleware($rateLimiter);
        $next = static fn(Request $r): Response => new Response(body: 'ok');

        $middleware->process(self::makeRequest(Method::DELETE, '/admin/users/1'), $next);
    }
}
