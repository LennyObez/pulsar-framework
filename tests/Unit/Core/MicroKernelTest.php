<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Core\MicroKernel;
use Pulsar\ErrorHandling\ProductionRenderer;
use Pulsar\Http\Message\BodyTooLargeException;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use ReflectionMethod;
use RuntimeException;
use stdClass;
use Throwable;

use function ini_get;
use function json_decode;
use function preg_replace;
use function strip_tags;

#[CoversClass(MicroKernel::class)]
#[CoversClass(ProductionRenderer::class)]
final class MicroKernelTest extends TestCase
{
    private MicroKernel $app;
    private string $errorLogFile;
    private string $previousErrorLog;

    protected function setUp(): void
    {
        $this->app = MicroKernel::create();

        $this->errorLogFile = sys_get_temp_dir() . '/pulsar_microkernel_err_' . uniqid() . '.log';
        $this->previousErrorLog = (string) ini_get('error_log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);

        if (is_file($this->errorLogFile)) {
            unlink($this->errorLogFile);
        }
    }

    /**
     * Point PHP's error log at a fixture for the remainder of this test.
     *
     * The generic-error path tells the client nothing, so everything it knows
     * goes to the error log — which is what this test asserts. Called from
     * inside the test body rather than setUp() because PHPUnit installs its own
     * error-log destination *between* setUp() and the test method: a redirect
     * set any earlier is discarded, and the line lands in PHPUnit's capture as
     * stray output instead of somewhere the test can read it.
     */
    private function captureErrorLog(): void
    {
        ini_set('error_log', $this->errorLogFile);
    }

    private function errorLogContents(): string
    {
        return is_file($this->errorLogFile)
            ? (string) file_get_contents($this->errorLogFile)
            : '';
    }

    public function testGetRouteReturnsStringResponse(): void
    {
        $this->app->get('/hello', fn() => 'Hello, World!');

        $request = $this->createRequest('GET', '/hello');
        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Hello, World!', (string) $response->getBody());
    }

    public function testGetRouteWithParameter(): void
    {
        $this->app->get('/hello/{name}', fn(ServerRequestInterface $r, string $name) => "Hello, $name!");

        $request = $this->createRequest('GET', '/hello/Alice');
        $response = $this->app->handle($request);

        self::assertStringContainsString('Hello, Alice!', (string) $response->getBody());
    }

    public function testPostRoute(): void
    {
        $this->app->post('/api/data', fn() => Response::json(['ok' => true]));

        $request = $this->createRequest('POST', '/api/data');
        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['ok']);
    }

    public function testPutRoute(): void
    {
        $this->app->put('/api/item/{id}', fn(ServerRequestInterface $r, string $id) => "Updated $id");

        $request = $this->createRequest('PUT', '/api/item/42');
        $response = $this->app->handle($request);

        self::assertStringContainsString('Updated 42', (string) $response->getBody());
    }

    public function testPatchRoute(): void
    {
        $this->app->patch('/api/item/{id}', fn(ServerRequestInterface $r, string $id) => "Patched $id");

        $request = $this->createRequest('PATCH', '/api/item/99');
        $response = $this->app->handle($request);

        self::assertStringContainsString('Patched 99', (string) $response->getBody());
    }

    public function testDeleteRoute(): void
    {
        $this->app->delete('/api/item/{id}', fn(ServerRequestInterface $r, string $id) => "Deleted $id");

        $request = $this->createRequest('DELETE', '/api/item/7');
        $response = $this->app->handle($request);

        self::assertStringContainsString('Deleted 7', (string) $response->getBody());
    }

    public function testRouteGroupWithPrefix(): void
    {
        $this->app->group('/api', function (\Pulsar\Routing\Router $router) {
            $router->get('/users', fn() => 'users list');
            $router->get('/posts', fn() => 'posts list');
        });

        $request = $this->createRequest('GET', '/api/users');
        $response = $this->app->handle($request);

        self::assertStringContainsString('users list', (string) $response->getBody());
    }

    public function testNotFoundReturns404Json(): void
    {
        $this->app->get('/exists', fn() => 'ok');

        $request = $this->createRequest('GET', '/nonexistent');
        $response = $this->app->handle($request);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testArrayReturnConvertsToJson(): void
    {
        $this->app->get('/api/status', fn() => ['status' => 'ok', 'version' => '1.0']);

        $request = $this->createRequest('GET', '/api/status');
        $response = $this->app->handle($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('ok', $body['status']);
    }

    public function testResponseObjectPassesThrough(): void
    {
        $this->app->get('/custom', fn() => Response::json(['custom' => true], 201));

        $request = $this->createRequest('GET', '/custom');
        $response = $this->app->handle($request);

        self::assertSame(201, $response->getStatusCode());
    }

    public function testBindRegistersClosureInContainer(): void
    {
        $this->app->bind('greeting', fn() => new stdClass());

        $container = $this->app->container();
        $result = $container->get('greeting');

        self::assertInstanceOf(stdClass::class, $result);
    }

    public function testContainerReturnsInstance(): void
    {
        $container = $this->app->container();

        self::assertInstanceOf(\Pulsar\Container\ContainerInterface::class, $container);
    }

    public function testMultipleRequestsWorkWithSameKernel(): void
    {
        $this->app->get('/a', fn() => 'route a');
        $this->app->get('/b', fn() => 'route b');

        $responseA = $this->app->handle($this->createRequest('GET', '/a'));
        $responseB = $this->app->handle($this->createRequest('GET', '/b'));

        self::assertStringContainsString('route a', (string) $responseA->getBody());
        self::assertStringContainsString('route b', (string) $responseB->getBody());
    }

    /**
     * `handle()` caught RoutingException and nothing else, so a throwing route
     * handler escaped to the SAPI, which prints the class, the message and the
     * absolute source path with `display_errors` on.
     */
    public function testHandlerExceptionRendersGenericErrorInsteadOfEscaping(): void
    {
        $this->captureErrorLog();

        $this->app->get('/boom', function (): never {
            throw new RuntimeException('secret detail from ' . __FILE__);
        });

        $response = $this->app->handle($this->createRequest('GET', '/boom'));

        self::assertSame(500, $response->getStatusCode());

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('secret detail', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
        self::assertStringNotContainsString(__FILE__, $body);
        self::assertStringNotContainsString('.php', $body);
        self::assertNoPathSeparatorInText($body);

        // What the client is not told, the operator is.
        self::assertStringContainsString('secret detail', $this->errorLogContents());
    }

    /**
     * `run()` builds the request from the superglobals before anything is in a
     * position to catch. A body over the cap raised BodyTooLargeException
     * straight into the SAPI.
     */
    public function testOversizedBodyBeforeRequestExistsRendersGeneric413(): void
    {
        $response = $this->preRequestFailureResponse(
            BodyTooLargeException::exceedsLimit(10_485_761, 10_485_760),
        );

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('BodyTooLargeException', $body);
        self::assertStringNotContainsString('10485761', $body);
        self::assertNoPathSeparatorInText($body);
    }

    public function testUnparseableRequestBeforePipelineRendersGeneric400(): void
    {
        $response = $this->preRequestFailureResponse(new RuntimeException('malformed'));

        self::assertSame(400, $response->getStatusCode());
        self::assertNoPathSeparatorInText((string) $response->getBody());
    }

    private function preRequestFailureResponse(Throwable $e): ResponseInterface
    {
        /** @var ResponseInterface */
        return new ReflectionMethod($this->app, 'preRequestFailureResponse')->invoke($this->app, $e);
    }

    /**
     * The closing tags of the generic page are the only slashes it may contain.
     * Drop the inline stylesheet and the markup, and no separator of either
     * platform may survive in what is left.
     */
    private static function assertNoPathSeparatorInText(string $html): void
    {
        $withoutStyle = (string) preg_replace('#<style\b[^>]*>.*?</style>#s', '', $html);
        $text = strip_tags($withoutStyle);

        self::assertStringNotContainsString('/', $text, 'Rendered error text contains a path separator');
        self::assertStringNotContainsString('\\', $text, 'Rendered error text contains a path separator');
    }

    private function createRequest(string $method, string $path): ServerRequestInterface
    {
        return new ServerRequest(
            method: $method,
            uri: "http://localhost$path",
        );
    }
}
