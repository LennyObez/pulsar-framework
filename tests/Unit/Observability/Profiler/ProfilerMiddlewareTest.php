<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Profiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Observability\Profiler\ProfilerMiddleware;
use Pulsar\Observability\Profiler\RequestProfiler;

use function str_contains;

#[CoversClass(ProfilerMiddleware::class)]
final class ProfilerMiddlewareTest extends TestCase
{
    #[Test]
    public function addsServerTimingHeaderWhenEnabled(): void
    {
        $middleware = new ProfilerMiddleware(new RequestProfiler(enabled: true));

        $response = $middleware->process($this->request(), $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('total;dur=', $response->getHeaderLine('Server-Timing'));
    }

    #[Test]
    public function includesDbTimingWhenQueriesAreRecorded(): void
    {
        $profiler = new RequestProfiler(enabled: true);
        $middleware = new ProfilerMiddleware($profiler);

        $handler = new class ($profiler) implements RequestHandlerInterface {
            public function __construct(private readonly RequestProfiler $profiler) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->profiler->recordQuery('SELECT 1', 2.5, 1);

                return Response::text('OK');
            }
        };

        $serverTiming = $middleware->process($this->request(), $handler)->getHeaderLine('Server-Timing');

        self::assertTrue(str_contains($serverTiming, 'db;dur='), "expected db timing in: {$serverTiming}");
        self::assertStringContainsString('1 queries', $serverTiming);
    }

    #[Test]
    public function passesThroughWithoutHeaderWhenDisabled(): void
    {
        $middleware = new ProfilerMiddleware(new RequestProfiler(enabled: false));

        $response = $middleware->process($this->request(), $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Server-Timing'));
    }

    private function request(): ServerRequest
    {
        return new ServerRequest(method: 'GET', uri: '/dashboard');
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
