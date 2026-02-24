<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Psr7Bridge;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psr7Bridge\Adapter\Psr7ToPulsarRequest;
use Pulsar\Http\Method;

#[CoversClass(Psr7ToPulsarRequest::class)]
final class Psr7ToPulsarRequestTest extends TestCase
{
    #[Test]
    public function convertsMethodAndPath(): void
    {
        $psrRequest = new ServerRequest('DELETE', '/users/42');

        $adapter = new Psr7ToPulsarRequest();
        $pulsarRequest = $adapter->convert($psrRequest);

        self::assertSame(Method::DELETE, $pulsarRequest->method);
        self::assertSame('/users/42', $pulsarRequest->path);
    }

    #[Test]
    public function convertsQueryStringAndParams(): void
    {
        $psrRequest = new ServerRequest('GET', '/search?q=hello&page=2')
            ->withQueryParams(['q' => 'hello', 'page' => '2']);

        $adapter = new Psr7ToPulsarRequest();
        $pulsarRequest = $adapter->convert($psrRequest);

        self::assertSame('q=hello&page=2', $pulsarRequest->queryString);
        self::assertSame(['q' => 'hello', 'page' => '2'], $pulsarRequest->query);
    }

    #[Test]
    public function convertsHeaders(): void
    {
        $psrRequest = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: [
                'Accept' => 'application/json',
                'X-Multi' => ['one', 'two'],
            ],
        );

        $adapter = new Psr7ToPulsarRequest();
        $pulsarRequest = $adapter->convert($psrRequest);

        self::assertSame('application/json', $pulsarRequest->headers->first('Accept'));
        self::assertSame(['one', 'two'], $pulsarRequest->headers->get('X-Multi'));
    }

    #[Test]
    public function convertsBody(): void
    {
        $factory = new Psr17Factory();
        $psrRequest = new ServerRequest(
            method: 'POST',
            uri: '/api',
            body: $factory->createStream('{"key":"value"}'),
        );

        $adapter = new Psr7ToPulsarRequest();
        $pulsarRequest = $adapter->convert($psrRequest);

        self::assertSame('{"key":"value"}', $pulsarRequest->body);
    }

    #[Test]
    public function convertsParsedBody(): void
    {
        $psrRequest = new ServerRequest('POST', '/form')
            ->withParsedBody(['username' => 'admin']);

        $adapter = new Psr7ToPulsarRequest();
        $pulsarRequest = $adapter->convert($psrRequest);

        self::assertSame(['username' => 'admin'], $pulsarRequest->post);
    }

    #[Test]
    public function nonArrayParsedBodyDefaultsToEmptyArray(): void
    {
        $psrRequest = new ServerRequest('POST', '/form')
            ->withParsedBody(null);

        $adapter = new Psr7ToPulsarRequest();
        $pulsarRequest = $adapter->convert($psrRequest);

        self::assertSame([], $pulsarRequest->post);
    }

    #[Test]
    public function convertsCookies(): void
    {
        $psrRequest = new ServerRequest('GET', '/')
            ->withCookieParams(['session' => 'abc123']);

        $adapter = new Psr7ToPulsarRequest();
        $pulsarRequest = $adapter->convert($psrRequest);

        self::assertSame(['session' => 'abc123'], $pulsarRequest->cookies);
    }

    #[Test]
    public function convertsAttributes(): void
    {
        $psrRequest = new ServerRequest('GET', '/')
            ->withAttribute('user_id', 42)
            ->withAttribute('role', 'admin');

        $adapter = new Psr7ToPulsarRequest();
        $pulsarRequest = $adapter->convert($psrRequest);

        self::assertSame(42, $pulsarRequest->attributes['user_id']);
        self::assertSame('admin', $pulsarRequest->attributes['role']);
    }

    #[Test]
    public function convertsProtocolVersion(): void
    {
        $psrRequest = new ServerRequest('GET', '/')
            ->withProtocolVersion('2.0');

        $adapter = new Psr7ToPulsarRequest();
        $pulsarRequest = $adapter->convert($psrRequest);

        self::assertSame('2.0', $pulsarRequest->protocolVersion);
    }

    #[Test]
    public function convertsFullUri(): void
    {
        $psrRequest = new ServerRequest('GET', 'https://example.com/path?q=1');

        $adapter = new Psr7ToPulsarRequest();
        $pulsarRequest = $adapter->convert($psrRequest);

        self::assertSame('https://example.com/path?q=1', $pulsarRequest->uri);
    }
}
