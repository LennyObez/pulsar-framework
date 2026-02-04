<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Psr7Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psr7Bridge\Adapter\Psr7ToPulsarRequest;
use Pulsar\Extension\Psr7Bridge\Adapter\PulsarToPsr7Request;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;

#[CoversClass(PulsarToPsr7Request::class)]
#[CoversClass(Psr7ToPulsarRequest::class)]
final class PulsarToPsr7RequestTest extends TestCase
{
    #[Test]
    public function convertsMethodAndUri(): void
    {
        $request = new Request(
            method: Method::POST,
            uri: '/users?page=1',
            path: '/users',
            queryString: 'page=1',
            headers: new HeaderBag(),
            body: '',
        );

        $adapter = new PulsarToPsr7Request();
        $psrRequest = $adapter->convert($request);

        self::assertSame('POST', $psrRequest->getMethod());
        self::assertSame('/users', $psrRequest->getUri()->getPath());
        self::assertSame('page=1', $psrRequest->getUri()->getQuery());
    }

    #[Test]
    public function convertsHeaders(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag([
                'Content-Type' => 'application/json',
                'X-Custom' => ['value1', 'value2'],
            ]),
            body: '',
        );

        $adapter = new PulsarToPsr7Request();
        $psrRequest = $adapter->convert($request);

        self::assertSame(['application/json'], $psrRequest->getHeader('Content-Type'));
        self::assertSame(['value1', 'value2'], $psrRequest->getHeader('X-Custom'));
    }

    #[Test]
    public function convertsBody(): void
    {
        $body = '{"name":"test"}';
        $request = new Request(
            method: Method::POST,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Content-Type' => 'application/json']),
            body: $body,
        );

        $adapter = new PulsarToPsr7Request();
        $psrRequest = $adapter->convert($request);

        self::assertSame($body, (string) $psrRequest->getBody());
    }

    #[Test]
    public function convertsQueryParams(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/search?q=test&page=2',
            path: '/search',
            queryString: 'q=test&page=2',
            headers: new HeaderBag(),
            body: '',
            query: ['q' => 'test', 'page' => '2'],
        );

        $adapter = new PulsarToPsr7Request();
        $psrRequest = $adapter->convert($request);

        self::assertSame(['q' => 'test', 'page' => '2'], $psrRequest->getQueryParams());
    }

    #[Test]
    public function convertsPostParams(): void
    {
        $request = new Request(
            method: Method::POST,
            uri: '/form',
            path: '/form',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            post: ['username' => 'admin', 'password' => 'secret'],
        );

        $adapter = new PulsarToPsr7Request();
        $psrRequest = $adapter->convert($request);

        self::assertSame(['username' => 'admin', 'password' => 'secret'], $psrRequest->getParsedBody());
    }

    #[Test]
    public function convertsCookies(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            cookies: ['session_id' => 'abc123'],
        );

        $adapter = new PulsarToPsr7Request();
        $psrRequest = $adapter->convert($request);

        self::assertSame(['session_id' => 'abc123'], $psrRequest->getCookieParams());
    }

    #[Test]
    public function convertsServerParams(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            server: ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $adapter = new PulsarToPsr7Request();
        $psrRequest = $adapter->convert($request);

        self::assertSame(['REMOTE_ADDR' => '127.0.0.1'], $psrRequest->getServerParams());
    }

    #[Test]
    public function convertsAttributes(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            attributes: ['route_id' => 42, 'tenant' => 'acme'],
        );

        $adapter = new PulsarToPsr7Request();
        $psrRequest = $adapter->convert($request);

        self::assertSame(42, $psrRequest->getAttribute('route_id'));
        self::assertSame('acme', $psrRequest->getAttribute('tenant'));
    }

    #[Test]
    public function convertsProtocolVersion(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            protocolVersion: '2.0',
        );

        $adapter = new PulsarToPsr7Request();
        $psrRequest = $adapter->convert($request);

        self::assertSame('2.0', $psrRequest->getProtocolVersion());
    }

    #[Test]
    public function roundtripPreservesAllFields(): void
    {
        $original = new Request(
            method: Method::PUT,
            uri: '/api/users/5?fields=name',
            path: '/api/users/5',
            queryString: 'fields=name',
            headers: new HeaderBag([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer token123',
            ]),
            body: '{"name":"updated"}',
            query: ['fields' => 'name'],
            post: ['name' => 'updated'],
            cookies: ['session' => 'xyz'],
            server: ['REMOTE_ADDR' => '10.0.0.1'],
            attributes: ['user_id' => 5],
            protocolVersion: '1.1',
        );

        $toPsr7 = new PulsarToPsr7Request();
        $toPulsar = new Psr7ToPulsarRequest();

        $psrRequest = $toPsr7->convert($original);
        $roundtripped = $toPulsar->convert($psrRequest);

        self::assertSame($original->method, $roundtripped->method);
        self::assertSame($original->path, $roundtripped->path);
        self::assertSame($original->queryString, $roundtripped->queryString);
        self::assertSame($original->body, $roundtripped->body);
        self::assertSame($original->query, $roundtripped->query);
        self::assertSame($original->post, $roundtripped->post);
        self::assertSame($original->cookies, $roundtripped->cookies);
        self::assertSame($original->server, $roundtripped->server);
        self::assertSame($original->attributes, $roundtripped->attributes);
        self::assertSame($original->protocolVersion, $roundtripped->protocolVersion);

        // Headers are preserved (case-insensitive)
        self::assertSame(
            $original->headers->first('Content-Type'),
            $roundtripped->headers->first('Content-Type'),
        );
        self::assertSame(
            $original->headers->first('Authorization'),
            $roundtripped->headers->first('Authorization'),
        );
    }

    #[Test]
    public function emptyPostBodySetsNullParsedBody(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );

        $adapter = new PulsarToPsr7Request();
        $psrRequest = $adapter->convert($request);

        self::assertNull($psrRequest->getParsedBody());
    }
}
