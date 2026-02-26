<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Container\Container;
use Pulsar\Core\Kernel;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;

/**
 * End-to-end tests for the complete HTTP request lifecycle.
 *
 * Verifies that a request flows through the Kernel from creation to response:
 * boot -> route matching -> handler dispatch -> response generation.
 */
#[CoversClass(Kernel::class)]
#[CoversClass(Router::class)]
#[CoversClass(Container::class)]
final class FullRequestLifecycleTest extends TestCase
{
    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, mixed> $parsedBody
     */
    private function createRequest(
        string $method = 'GET',
        string $path = '/',
        string $body = '',
        array $headers = [],
        array $parsedBody = [],
    ): ServerRequest {
        return new ServerRequest(
            method: $method,
            uri: $path,
            headers: $headers,
            body: $body,
            parsedBody: $parsedBody !== [] ? $parsedBody : null,
        );
    }

    #[Test]
    public function fullGetRequestLifecycleReturnsExpectedResponse(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/', fn() => Response::text('Welcome to Pulsar'));

        $request = $this->createRequest();
        $response = $kernel->handle($request);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('Welcome to Pulsar', (string) $response->getBody());
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function unmatchedRouteThrows404RoutingException(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/', fn() => Response::text('home'));

        $this->expectException(RoutingException::class);
        $this->expectExceptionCode(404);

        $kernel->handle($this->createRequest(path: '/nonexistent'));
    }

    #[Test]
    public function postRequestIsHandledCorrectly(): void
    {
        $kernel = new Kernel();
        $kernel->router()->post('/submit', function (ServerRequestInterface $request): Response {
            return Response::json(['status' => 'received', 'method' => $request->getMethod()]);
        });

        $request = $this->createRequest(method: 'POST', path: '/submit');
        $response = $kernel->handle($request);

        $body = (string) $response->getBody();
        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertStringContainsString('"status":"received"', $body);
        self::assertStringContainsString('"method":"POST"', $body);
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function routeWithParametersExtractsAndPassesThem(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/users/{id}/posts/{postId}', function (ServerRequestInterface $request, array $params): Response {
            return Response::json([
                'user_id' => $params['id'],
                'post_id' => $params['postId'],
            ]);
        });

        $request = $this->createRequest(path: '/users/42/posts/7');
        $response = $kernel->handle($request);

        $body = (string) $response->getBody();
        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertStringContainsString('"user_id":"42"', $body);
        self::assertStringContainsString('"post_id":"7"', $body);
    }

    #[Test]
    public function routeParametersAreAddedToRequestAttributes(): void
    {
        $kernel = new Kernel();
        $capturedUserId = null;

        $kernel->router()->get('/accounts/{accountId}', function (ServerRequestInterface $request) use (&$capturedUserId): Response {
            $capturedUserId = $request->getAttribute('accountId');
            return Response::text('ok');
        });

        $kernel->handle($this->createRequest(path: '/accounts/abc-123'));

        self::assertSame('abc-123', $capturedUserId);
    }

    #[Test]
    public function jsonResponseIsProperlyFormatted(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/api/data', fn() => Response::json([
            'items' => [1, 2, 3],
            'total' => 3,
        ]));

        $response = $kernel->handle($this->createRequest(path: '/api/data'));

        $body = (string) $response->getBody();
        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));

        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertSame([1, 2, 3], $decoded['items']);
        self::assertSame(3, $decoded['total']);
    }

    #[Test]
    public function htmlStringReturnIsConvertedToHtmlResponse(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/page', fn() => '<h1>Hello Pulsar</h1>');

        $response = $kernel->handle($this->createRequest(path: '/page'));

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('<h1>Hello Pulsar</h1>', (string) $response->getBody());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function methodNotAllowedThrows405(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/resource', fn() => Response::text('ok'));

        $this->expectException(RoutingException::class);
        $this->expectExceptionCode(405);

        $kernel->handle($this->createRequest(method: 'DELETE', path: '/resource'));
    }

    #[Test]
    public function multipleRoutesAreDispatchedCorrectly(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/alpha', fn() => Response::text('route-alpha'));
        $kernel->router()->get('/beta', fn() => Response::text('route-beta'));
        $kernel->router()->post('/gamma', fn() => Response::text('route-gamma'));

        $responseAlpha = $kernel->handle($this->createRequest(path: '/alpha'));
        $responseBeta = $kernel->handle($this->createRequest(path: '/beta'));
        $responseGamma = $kernel->handle($this->createRequest(method: 'POST', path: '/gamma'));

        self::assertSame('route-alpha', (string) $responseAlpha->getBody());
        self::assertSame('route-beta', (string) $responseBeta->getBody());
        self::assertSame('route-gamma', (string) $responseGamma->getBody());
    }

    #[Test]
    public function putAndPatchAndDeleteRoutesWork(): void
    {
        $kernel = new Kernel();
        $kernel->router()->put('/items/{id}', fn() => Response::text('updated'));
        $kernel->router()->patch('/items/{id}', fn() => Response::text('patched'));
        $kernel->router()->delete('/items/{id}', fn() => Response::text('deleted'));

        $putResponse = $kernel->handle($this->createRequest(method: 'PUT', path: '/items/1'));
        $patchResponse = $kernel->handle($this->createRequest(method: 'PATCH', path: '/items/1'));
        $deleteResponse = $kernel->handle($this->createRequest(method: 'DELETE', path: '/items/1'));

        self::assertSame('updated', (string) $putResponse->getBody());
        self::assertSame('patched', (string) $patchResponse->getBody());
        self::assertSame('deleted', (string) $deleteResponse->getBody());
    }

    #[Test]
    public function containerResolvedControllerHandlesRequest(): void
    {
        $container = new Container();
        $kernel = new Kernel($container);
        $kernel->router()->get('/ctrl', [LifecycleTestController::class, 'handle']);

        $response = $kernel->handle($this->createRequest(path: '/ctrl'));

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('controller handled', (string) $response->getBody());
    }
}

/**
 * Test controller for container resolution tests.
 */
class LifecycleTestController
{
    /**
     * @param array<string, string> $params
     */
    public function handle(ServerRequestInterface $request, array $params): Response
    {
        return Response::text('controller handled');
    }
}
