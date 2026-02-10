<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Internal\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Internal\Middleware\AdminRateLimitMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\RateLimit\RateLimiterInterface;
use Pulsar\Http\RateLimit\RateLimitResult;
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

    private static function makeRequest(string $method = 'GET', string $path = '/admin/users', ?IdentityInterface $identity = null): ServerRequest
    {
        $attributes = [];
        if ($identity !== null) {
            $attributes['identity'] = $identity;
        }

        return new ServerRequest(
            method: $method,
            uri: $path,
            attributes: $attributes,
        );
    }

    private static function makeHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(body: 'ok');
            }
        };
    }

    #[Test]
    public function allowsRequestWhenNotRateLimited(): void
    {
        $this->rateLimiter->method('hit')->willReturn(
            new RateLimitResult(allowed: true, limit: 120, remaining: 119, retryAfter: 0),
        );

        $response = $this->middleware->process(self::makeRequest(), self::makeHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('120', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('119', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function rejectsRequestWhenRateLimited(): void
    {
        $this->rateLimiter->method('hit')->willReturn(
            new RateLimitResult(allowed: false, limit: 120, remaining: 0, retryAfter: 45),
        );

        $response = $this->middleware->process(self::makeRequest(), self::makeHandler());

        self::assertSame(ResponseStatus::TooManyRequests->value, $response->getStatusCode());
        self::assertSame('45', $response->getHeaderLine('Retry-After'));
        self::assertStringContainsString('Rate limit exceeded', (string) $response->getBody());
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

        $middleware->process(self::makeRequest('GET'), self::makeHandler());
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

        $middleware->process(self::makeRequest('POST', '/admin/users'), self::makeHandler());
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

        $middleware->process(self::makeRequest('GET', '/admin/users/export'), self::makeHandler());
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

        $middleware->process(self::makeRequest('GET', '/admin/users', $identity), self::makeHandler());
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

        $middleware->process(self::makeRequest(), self::makeHandler());
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

        $middleware->process(self::makeRequest('DELETE', '/admin/users/1'), self::makeHandler());
    }
}
