<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Inertia;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Factory\ResponseFactory;
use Pulsar\Http\Factory\ServerRequestFactory;
use Pulsar\Inertia\InertiaConfig;
use Pulsar\Inertia\InertiaMiddleware;

/**
 * Edge case coverage for InertiaMiddleware.
 */
#[CoversClass(InertiaMiddleware::class)]
final class InertiaMiddlewareEdgeTest extends TestCase
{
    #[Test]
    public function nonInertiaRequestPassesThroughUnchanged(): void
    {
        $middleware = new InertiaMiddleware();
        $request = $this->createRequest('/dashboard');
        $response = $this->createStub(ResponseInterface::class);
        $handler = $this->createHandler($response);

        $result = $middleware->process($request, $handler);

        self::assertSame($response, $result);
    }

    #[Test]
    public function inertiaRequestAddsVaryHeader(): void
    {
        $middleware = new InertiaMiddleware();
        $request = $this->createRequest('/dashboard')
            ->withHeader('X-Inertia', 'true');

        $response = new ResponseFactory()->createResponse(200);
        $handler = $this->createHandler($response);

        $result = $middleware->process($request, $handler);

        self::assertStringContainsString('X-Inertia', $result->getHeaderLine('Vary'));
    }

    #[Test]
    public function versionMismatchReturns409WithLocation(): void
    {
        $middleware = new InertiaMiddleware(
            assetVersion: 'v2',
        );

        $request = $this->createRequest('/page')
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', 'v1');

        $handler = $this->createHandler(new ResponseFactory()->createResponse(200));

        $result = $middleware->process($request, $handler);

        self::assertSame(409, $result->getStatusCode());
        self::assertNotEmpty($result->getHeaderLine('X-Inertia-Location'));
    }

    #[Test]
    public function matchingVersionDoesNotForceRefresh(): void
    {
        $middleware = new InertiaMiddleware(
            assetVersion: 'v2',
        );

        $request = $this->createRequest('/page')
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', 'v2');

        $response = new ResponseFactory()->createResponse(200);
        $handler = $this->createHandler($response);

        $result = $middleware->process($request, $handler);

        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function sharePropsArePassedViaRequestAttribute(): void
    {
        $middleware = new InertiaMiddleware();
        $middleware->share(['user' => 'Bob', 'csrf' => 'token123']);

        $capturedRequest = null;
        $handler = new class ($capturedRequest) implements RequestHandlerInterface {
            /** @param ServerRequestInterface|null $captured */
            public function __construct(private ?ServerRequestInterface &$captured) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;

                return new ResponseFactory()->createResponse(200);
            }

            public function getCaptured(): ?ServerRequestInterface
            {
                return $this->captured;
            }
        };

        $request = $this->createRequest('/test');
        $middleware->process($request, $handler);

        self::assertNotNull($capturedRequest);
        /** @var array<string, mixed> $sharedProps */
        $sharedProps = $capturedRequest->getAttribute('inertia_shared_props');
        self::assertSame('Bob', $sharedProps['user']);
        self::assertSame('token123', $sharedProps['csrf']);
    }

    #[Test]
    public function emptyAssetVersionDoesNotForceRefresh(): void
    {
        $middleware = new InertiaMiddleware(assetVersion: '');

        $request = $this->createRequest('/page')
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', 'anything');

        $response = new ResponseFactory()->createResponse(200);
        $handler = $this->createHandler($response);

        $result = $middleware->process($request, $handler);

        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function customVersionHeaderIsUsed(): void
    {
        $config = new InertiaConfig(versionHeader: 'X-Custom-Version');
        $middleware = new InertiaMiddleware(
            config: $config,
            assetVersion: 'v3',
        );

        $request = $this->createRequest('/page')
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Custom-Version', 'v1');

        $handler = $this->createHandler(new ResponseFactory()->createResponse(200));

        $result = $middleware->process($request, $handler);

        self::assertSame(409, $result->getStatusCode());
    }

    #[Test]
    public function shareIsMergedAcrossMultipleCalls(): void
    {
        $middleware = new InertiaMiddleware();
        $middleware->share(['a' => 1]);
        $middleware->share(['b' => 2]);

        $capturedRequest = null;
        $handler = new class ($capturedRequest) implements RequestHandlerInterface {
            /** @param ServerRequestInterface|null $captured */
            public function __construct(private ?ServerRequestInterface &$captured) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;

                return new ResponseFactory()->createResponse(200);
            }

            public function getCaptured(): ?ServerRequestInterface
            {
                return $this->captured;
            }
        };

        $middleware->process($this->createRequest('/'), $handler);

        self::assertNotNull($capturedRequest);
        /** @var array<string, mixed> $props */
        $props = $capturedRequest->getAttribute('inertia_shared_props');
        self::assertSame(1, $props['a']);
        self::assertSame(2, $props['b']);
    }

    private function createRequest(string $path): ServerRequestInterface
    {
        return new ServerRequestFactory()->createServerRequest('GET', $path);
    }

    private function createHandler(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        return $handler;
    }
}
