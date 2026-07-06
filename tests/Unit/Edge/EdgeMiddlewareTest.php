<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Edge;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Edge\EdgeFunctionPipeline;
use Pulsar\Edge\EdgeMiddleware;
use Pulsar\Edge\GeoRoutingEdgeFunction;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(EdgeMiddleware::class)]
final class EdgeMiddlewareTest extends TestCase
{
    #[Test]
    public function geoFunctionShortCircuitsWithARedirect(): void
    {
        $pipeline = new EdgeFunctionPipeline();
        $pipeline->add(new GeoRoutingEdgeFunction(['DE' => '/de']));
        $middleware = new EdgeMiddleware($pipeline, 'CF-IPCountry');

        $request = new ServerRequest(method: 'GET', uri: '/', headers: ['CF-IPCountry' => 'DE']);

        $response = $middleware->process($request, $this->failingHandler());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/de', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function passesThroughToTheHandlerWhenNoFunctionMatches(): void
    {
        $pipeline = new EdgeFunctionPipeline();
        $pipeline->add(new GeoRoutingEdgeFunction(['DE' => '/de'])); // no default redirect
        $middleware = new EdgeMiddleware($pipeline, 'CF-IPCountry');

        // A country with no rule and no default => the function returns null.
        $request = new ServerRequest(method: 'GET', uri: '/', headers: ['CF-IPCountry' => 'JP']);

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', (string) $response->getBody());
    }

    #[Test]
    public function passesThroughWhenGeoHeaderIsAbsent(): void
    {
        $pipeline = new EdgeFunctionPipeline();
        $pipeline->add(new GeoRoutingEdgeFunction(['DE' => '/de']));
        $middleware = new EdgeMiddleware($pipeline, 'CF-IPCountry');

        $response = $middleware->process(new ServerRequest(method: 'GET', uri: '/'), $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
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

    private function failingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new LogicException('handler must not be reached when an edge function short-circuits');
            }
        };
    }
}
