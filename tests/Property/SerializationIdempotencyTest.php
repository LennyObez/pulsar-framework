<?php

declare(strict_types=1);

namespace Pulsar\Tests\Property;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Message\Uri;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(Uri::class)]
#[CoversClass(ServerRequest::class)]
#[CoversClass(Request::class)]
#[CoversClass(Route::class)]
#[CoversClass(MatchedRoute::class)]
#[Group('property')]
final class SerializationIdempotencyTest extends TestCase
{
    /** @return class-string */
    private static function handler(string $name): string
    {
        /** @var class-string */
        return $name;
    }

    #[Test]
    public function uriToStringParseRoundtripIsIdempotent(): void
    {
        $uris = [
            'https://example.com/path?query=value#fragment',
            'http://user:pass@example.com:8080/path',
            'https://example.com',
            'https://example.com/',
            'https://example.com/path/to/resource',
            'https://example.com/path?a=1&b=2&c=3',
            'https://example.com/path?key=' . urlencode('value with spaces'),
            '/relative/path?query=1',
        ];

        foreach ($uris as $uriString) {
            $uri = Uri::fromString($uriString);
            $serialized = (string) $uri;
            $reparsed = Uri::fromString($serialized);
            $reserialized = (string) $reparsed;

            self::assertSame(
                $serialized,
                $reserialized,
                "URI roundtrip not idempotent for: {$uriString}",
            );
        }
    }

    #[Test]
    public function jsonEncodeDecodeRoundtripPreservesStructure(): void
    {
        $structures = [
            ['key' => 'value'],
            ['nested' => ['deep' => ['deeper' => 'value']]],
            ['array' => [1, 2, 3, 4, 5]],
            ['mixed' => ['string' => 'text', 'int' => 42, 'float' => 3.14, 'bool' => true, 'null' => null]],
            ['unicode' => '日本語テスト'],
            ['empty_array' => [], 'empty_string' => ''],
            ['special' => "line1\nline2\ttab"],
        ];

        foreach ($structures as $original) {
            $json = json_encode($original, JSON_THROW_ON_ERROR);
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $rejson = json_encode($decoded, JSON_THROW_ON_ERROR);

            self::assertSame($json, $rejson, 'JSON roundtrip not idempotent');
            self::assertSame($original, $decoded, 'JSON decode does not match original structure');
        }
    }

    #[Test]
    public function requestJsonRoundtripPreservesData(): void
    {
        $testData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'age' => 30,
            'preferences' => [
                'theme' => 'dark',
                'language' => 'en',
            ],
        ];

        $body = json_encode($testData, JSON_THROW_ON_ERROR);

        $request = new Request(
            method: Method::POST,
            uri: '/api/users',
            path: '/api/users',
            queryString: '',
            headers: new HeaderBag(['Content-Type' => 'application/json']),
            body: $body,
        );

        $parsed = $request->json();
        self::assertSame($testData, $parsed);

        // Re-encode and re-parse
        $reencoded = json_encode($parsed, JSON_THROW_ON_ERROR);
        $request2 = new Request(
            method: Method::POST,
            uri: '/api/users',
            path: '/api/users',
            queryString: '',
            headers: new HeaderBag(['Content-Type' => 'application/json']),
            body: $reencoded,
        );

        self::assertSame($testData, $request2->json());
    }

    #[Test]
    public function headerBagPreservesHeaderValues(): void
    {
        $originalHeaders = [
            'Content-Type' => 'application/json',
            'Accept' => 'text/html, application/json',
            'X-Custom' => 'custom-value',
            'Authorization' => 'Bearer token123',
        ];

        $bag = new HeaderBag($originalHeaders);

        foreach ($originalHeaders as $name => $value) {
            self::assertSame($value, $bag->first($name), "Header '{$name}' value not preserved");
        }
    }

    #[Test]
    public function serverRequestWithQueryParamsRoundtrip(): void
    {
        $queryParams = [
            'page' => '1',
            'limit' => '20',
            'sort' => 'name',
            'filter' => 'active',
        ];

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/users?page=1&limit=20&sort=name&filter=active',
            queryParams: $queryParams,
        );

        $retrieved = $request->getQueryParams();
        self::assertSame($queryParams, $retrieved);

        // withQueryParams should preserve the same data
        $clone = $request->withQueryParams($queryParams);
        self::assertSame($queryParams, $clone->getQueryParams());
    }

    #[Test]
    public function routeParameterExtractionIsConsistent(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/users/{userId}/posts/{postId}',
            handler: self::handler('PostController'),
            name: 'user.post.show',
        );

        $testCases = [
            '/users/42/posts/100' => ['userId' => '42', 'postId' => '100'],
            '/users/1/posts/1' => ['userId' => '1', 'postId' => '1'],
            '/users/abc/posts/def' => ['userId' => 'abc', 'postId' => 'def'],
        ];

        foreach ($testCases as $path => $expectedParams) {
            $params = $route->matchesPath($path);
            self::assertNotNull($params, "Route should match: {$path}");
            self::assertSame($expectedParams, $params, "Parameters mismatch for: {$path}");

            // Matching the same path twice should yield identical results
            $params2 = $route->matchesPath($path);
            self::assertSame($params, $params2, "Route matching not deterministic for: {$path}");
        }
    }

    #[Test]
    public function uriComponentsArePreserved(): void
    {
        $uri = new Uri(
            scheme: 'https',
            userInfo: 'user:pass',
            host: 'example.com',
            port: 8443,
            path: '/api/v1/resource',
            query: 'key=value&other=data',
            fragment: 'section1',
        );

        self::assertSame('https', $uri->getScheme());
        self::assertSame('user:pass', $uri->getUserInfo());
        self::assertSame('example.com', $uri->getHost());
        self::assertSame(8443, $uri->getPort());
        self::assertSame('/api/v1/resource', $uri->getPath());
        self::assertSame('key=value&other=data', $uri->getQuery());
        self::assertSame('section1', $uri->getFragment());
    }

    #[Test]
    public function uriWithMethodsProduceCorrectOutput(): void
    {
        $uri = Uri::fromString('https://example.com/original?q=1');

        $modified = $uri
            ->withScheme('http')
            ->withHost('new.example.com')
            ->withPath('/new/path')
            ->withQuery('q=2')
            ->withFragment('bottom');

        self::assertSame('http://new.example.com/new/path?q=2#bottom', (string) $modified);

        // Original is unchanged (immutability)
        self::assertSame('https://example.com/original?q=1', (string) $uri);
    }

    #[Test]
    public function matchedRoutePreservesAllData(): void
    {
        $route = new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/api/{version}/users/{id}',
            handler: self::handler('UserController'),
            name: 'api.user.show',
            attributes: ['auth' => true, 'rate_limit' => 100],
            middleware: ['auth', 'throttle'],
        );

        $matched = new MatchedRoute($route, ['version' => 'v2', 'id' => '42']);

        self::assertSame($route, $matched->route);
        self::assertSame('v2', $matched->parameter('version'));
        self::assertSame('42', $matched->parameter('id'));
        self::assertSame('api.user.show', $matched->getName());
        self::assertSame('UserController', $matched->getHandler());
        self::assertSame(['auth', 'throttle'], $matched->getMiddleware());
        self::assertSame(['auth' => true, 'rate_limit' => 100], $matched->getAttributes());
    }
}
