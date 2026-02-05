<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Core\Kernel;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
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
     * @param array<string, mixed> $post
     */
    private function createRequest(
        Method $method = Method::GET,
        string $path = '/',
        string $body = '',
        HeaderBag $headers = new HeaderBag(),
        array $post = [],
    ): Request {
        return new Request(
            method: $method,
            uri: $path,
            path: $path,
            queryString: '',
            headers: $headers,
            body: $body,
            post: $post,
        );
    }

    #[Test]
    public function fullGetRequestLifecycleReturnsExpectedResponse(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/', fn() => Response::text('Welcome to Pulsar'));

        $request = $this->createRequest();
        $response = $kernel->handle($request);

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('Welcome to Pulsar', $response->body);
        self::assertSame('text/plain; charset=utf-8', $response->headers->first('Content-Type'));
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
        $kernel->router()->post('/submit', function (Request $request): Response {
            return Response::json(['status' => 'received', 'method' => $request->method->value]);
        });

        $request = $this->createRequest(method: Method::POST, path: '/submit');
        $response = $kernel->handle($request);

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertStringContainsString('"status":"received"', $response->body);
        self::assertStringContainsString('"method":"POST"', $response->body);
        self::assertSame('application/json; charset=utf-8', $response->headers->first('Content-Type'));
    }

    #[Test]
    public function routeWithParametersExtractsAndPassesThem(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/users/{id}/posts/{postId}', function (Request $request, array $params): Response {
            return Response::json([
                'user_id' => $params['id'],
                'post_id' => $params['postId'],
            ]);
        });

        $request = $this->createRequest(path: '/users/42/posts/7');
        $response = $kernel->handle($request);

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertStringContainsString('"user_id":"42"', $response->body);
        self::assertStringContainsString('"post_id":"7"', $response->body);
    }

    #[Test]
    public function routeParametersAreAddedToRequestAttributes(): void
    {
        $kernel = new Kernel();
        $capturedUserId = null;

        $kernel->router()->get('/accounts/{accountId}', function (Request $request) use (&$capturedUserId): Response {
            $capturedUserId = $request->attribute('accountId');
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

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers->first('Content-Type'));

        $decoded = json_decode($response->body, true);
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

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('<h1>Hello Pulsar</h1>', $response->body);
        self::assertSame('text/html; charset=utf-8', $response->headers->first('Content-Type'));
    }

    #[Test]
    public function methodNotAllowedThrows405(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/resource', fn() => Response::text('ok'));

        $this->expectException(RoutingException::class);
        $this->expectExceptionCode(405);

        $kernel->handle($this->createRequest(method: Method::DELETE, path: '/resource'));
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
        $responseGamma = $kernel->handle($this->createRequest(method: Method::POST, path: '/gamma'));

        self::assertSame('route-alpha', $responseAlpha->body);
        self::assertSame('route-beta', $responseBeta->body);
        self::assertSame('route-gamma', $responseGamma->body);
    }

    #[Test]
    public function putAndPatchAndDeleteRoutesWork(): void
    {
        $kernel = new Kernel();
        $kernel->router()->put('/items/{id}', fn() => Response::text('updated'));
        $kernel->router()->patch('/items/{id}', fn() => Response::text('patched'));
        $kernel->router()->delete('/items/{id}', fn() => Response::text('deleted'));

        $putResponse = $kernel->handle($this->createRequest(method: Method::PUT, path: '/items/1'));
        $patchResponse = $kernel->handle($this->createRequest(method: Method::PATCH, path: '/items/1'));
        $deleteResponse = $kernel->handle($this->createRequest(method: Method::DELETE, path: '/items/1'));

        self::assertSame('updated', $putResponse->body);
        self::assertSame('patched', $patchResponse->body);
        self::assertSame('deleted', $deleteResponse->body);
    }

    #[Test]
    public function containerResolvedControllerHandlesRequest(): void
    {
        $container = new Container();
        $kernel = new Kernel($container);
        $kernel->router()->get('/ctrl', [LifecycleTestController::class, 'handle']);

        $response = $kernel->handle($this->createRequest(path: '/ctrl'));

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('controller handled', $response->body);
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
    public function handle(Request $request, array $params): Response
    {
        return Response::text('controller handled');
    }
}
