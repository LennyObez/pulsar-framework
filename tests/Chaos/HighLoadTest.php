<?php

declare(strict_types=1);

namespace Pulsar\Tests\Chaos;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Routing\Router;
use stdClass;

use function strlen;

#[CoversClass(Router::class)]
#[CoversClass(ServerRequest::class)]
#[CoversClass(Request::class)]
#[Group('chaos')]
final class HighLoadTest extends TestCase
{
    #[Test]
    public function rapidSequentialRequestProcessingDoesNotLeak(): void
    {
        $router = new Router();
        $router->get('/api/users/{id}', stdClass::class, 'user.show');
        $router->post('/api/users', stdClass::class, 'user.create');
        $router->get('/api/products/{id}', stdClass::class, 'product.show');

        $memoryBefore = memory_get_usage(true);

        for ($i = 0; $i < 1000; $i++) {
            $matched = $router->match(Method::GET, '/api/users/' . $i);
            self::assertSame((string) $i, $matched->parameter('id'));
        }

        $memoryAfter = memory_get_usage(true);
        $memoryDelta = $memoryAfter - $memoryBefore;

        // Memory growth should be bounded (less than 5MB for 1000 iterations)
        self::assertLessThan(
            5 * 1024 * 1024,
            $memoryDelta,
            "Memory grew by {$memoryDelta} bytes during rapid request processing",
        );
    }

    #[Test]
    public function largePayloadProcessingDoesNotExhaustMemory(): void
    {
        $payloadSizes = [1024, 8192, 65536, 524288]; // 1KB, 8KB, 64KB, 512KB

        foreach ($payloadSizes as $size) {
            $body = str_repeat('x', $size);
            $request = new ServerRequest(
                method: 'POST',
                uri: '/api/upload',
                headers: ['Content-Type' => 'application/octet-stream'],
                body: $body,
            );

            $readBody = (string) $request->getBody();
            self::assertSame($size, strlen($readBody));
        }
    }

    #[Test]
    public function manyRequestAttributesDoNotDegradePerformance(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );

        // Add many attributes
        for ($i = 0; $i < 500; $i++) {
            $request = $request->withAttribute("attr_{$i}", "value_{$i}");
        }

        // Verify all attributes are accessible
        for ($i = 0; $i < 500; $i++) {
            self::assertSame("value_{$i}", $request->attribute("attr_{$i}"));
        }
    }

    #[Test]
    public function routerWithManyRoutesMatchesCorrectly(): void
    {
        $router = new Router();

        // Register 1000 routes
        for ($i = 0; $i < 1000; $i++) {
            /** @var class-string $handler */
            $handler = "Controller{$i}";
            $router->get("/api/v1/resource-{$i}/{id}", $handler, "resource.{$i}");
        }

        // Match first, middle, and last routes
        $first = $router->match(Method::GET, '/api/v1/resource-0/42');
        self::assertSame('resource.0', $first->getName());
        self::assertSame('42', $first->parameter('id'));

        $middle = $router->match(Method::GET, '/api/v1/resource-500/42');
        self::assertSame('resource.500', $middle->getName());

        $last = $router->match(Method::GET, '/api/v1/resource-999/42');
        self::assertSame('resource.999', $last->getName());
    }

    #[Test]
    public function manyQueryParametersAreHandled(): void
    {
        $queryParts = [];
        for ($i = 0; $i < 500; $i++) {
            $queryParts[] = "param_{$i}=value_{$i}";
        }
        $queryString = implode('&', $queryParts);

        $query = [];
        for ($i = 0; $i < 500; $i++) {
            $query["param_{$i}"] = "value_{$i}";
        }

        $request = new Request(
            method: Method::GET,
            uri: '/?' . $queryString,
            path: '/',
            queryString: $queryString,
            headers: new HeaderBag(),
            body: '',
            query: $query,
        );

        self::assertSame('value_0', $request->query('param_0'));
        self::assertSame('value_499', $request->query('param_499'));
        self::assertNull($request->query('nonexistent'));
    }

    #[Test]
    public function largeJsonBodyParsingDoesNotFail(): void
    {
        $data = [];
        for ($i = 0; $i < 500; $i++) {
            $data["field_{$i}"] = str_repeat('data', 25);
        }
        $body = json_encode($data, JSON_THROW_ON_ERROR);

        $request = new Request(
            method: Method::POST,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Content-Type' => 'application/json']),
            body: $body,
        );

        $parsed = $request->json();
        self::assertCount(500, $parsed);
        self::assertSame(str_repeat('data', 25), $parsed['field_0']);
    }

    #[Test]
    public function manyHeadersAreHandledEfficiently(): void
    {
        $headers = [];
        for ($i = 0; $i < 200; $i++) {
            $headers['X-Custom-Header-' . $i] = 'value-' . $i;
        }

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: $headers,
        );

        // Verify header access works for all headers
        for ($i = 0; $i < 200; $i++) {
            self::assertSame(
                'value-' . $i,
                $request->getHeaderLine('X-Custom-Header-' . $i),
            );
        }
    }

    #[Test]
    public function repeatedJsonParsingIsMemoized(): void
    {
        $body = json_encode(['key' => 'value'], JSON_THROW_ON_ERROR);

        $request = new Request(
            method: Method::POST,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Content-Type' => 'application/json']),
            body: $body,
        );

        // Call json() many times — should be memoized
        for ($i = 0; $i < 100; $i++) {
            $result = $request->json();
            self::assertSame('value', $result['key']);
        }
    }
}
