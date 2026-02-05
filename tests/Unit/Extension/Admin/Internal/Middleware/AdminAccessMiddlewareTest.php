<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Internal\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Internal\Middleware\AdminAccessMiddleware;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

#[CoversClass(AdminAccessMiddleware::class)]
final class AdminAccessMiddlewareTest extends TestCase
{
    private static function makeRequest(): Request
    {
        return new Request(
            method: Method::GET,
            uri: '/admin/dashboard',
            path: '/admin/dashboard',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );
    }

    #[Test]
    public function allowsRequestWhenEnabled(): void
    {
        $config = AdminConfig::fromArray(['enabled' => true]);
        $middleware = new AdminAccessMiddleware($config);

        $expectedResponse = Response::json(['status' => 'ok']);
        $next = static fn(Request $r): Response => $expectedResponse;

        $response = $middleware->process(self::makeRequest(), $next);

        self::assertSame(ResponseStatus::OK, $response->status);
    }

    #[Test]
    public function blocksRequestWhenDisabled(): void
    {
        $config = AdminConfig::fromArray(['enabled' => false]);
        $middleware = new AdminAccessMiddleware($config);

        $next = static fn(Request $r): Response => Response::json(['status' => 'ok']);

        $response = $middleware->process(self::makeRequest(), $next);

        self::assertSame(ResponseStatus::NotFound, $response->status);
        self::assertStringContainsString('disabled', $response->body);
    }

    #[Test]
    public function doesNotCallNextWhenDisabled(): void
    {
        $config = AdminConfig::fromArray(['enabled' => false]);
        $middleware = new AdminAccessMiddleware($config);

        $called = false;
        $next = static function (Request $r) use (&$called): Response {
            $called = true;
            return Response::json(['status' => 'ok']);
        };

        $middleware->process(self::makeRequest(), $next);

        self::assertFalse($called);
    }

    #[Test]
    public function passesRequestToNextWhenEnabled(): void
    {
        $config = AdminConfig::fromArray(['enabled' => true]);
        $middleware = new AdminAccessMiddleware($config);

        $receivedRequest = null;
        $next = static function (Request $r) use (&$receivedRequest): Response {
            $receivedRequest = $r;
            return Response::json(['status' => 'ok']);
        };

        $request = self::makeRequest();
        $middleware->process($request, $next);

        self::assertSame($request, $receivedRequest);
    }

    #[Test]
    public function defaultConfigIsDisabled(): void
    {
        $config = AdminConfig::fromArray([]);
        $middleware = new AdminAccessMiddleware($config);

        $next = static fn(Request $r): Response => Response::json(['status' => 'ok']);

        $response = $middleware->process(self::makeRequest(), $next);

        self::assertSame(ResponseStatus::NotFound, $response->status);
    }
}
