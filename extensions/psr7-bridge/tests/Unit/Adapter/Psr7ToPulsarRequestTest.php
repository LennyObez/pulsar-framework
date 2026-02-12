<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psr7Bridge\Tests\Unit\Adapter;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psr7Bridge\Adapter\Psr7ToPulsarRequest;
use Pulsar\Http\Method;

final class Psr7ToPulsarRequestTest extends TestCase
{
    private Psr7ToPulsarRequest $adapter;

    protected function setUp(): void
    {
        $this->adapter = new Psr7ToPulsarRequest();
    }

    #[Test]
    public function convertPreservesHttpMethod(): void
    {
        $psr = new ServerRequest('POST', 'https://example.com/api');
        $request = $this->adapter->convert($psr);

        self::assertSame(Method::POST, $request->method);
    }

    #[Test]
    public function convertPreservesUri(): void
    {
        $psr = new ServerRequest('GET', 'https://example.com/path?q=1');
        $request = $this->adapter->convert($psr);

        self::assertSame('https://example.com/path?q=1', $request->uri);
    }

    #[Test]
    public function convertPreservesPath(): void
    {
        $psr = new ServerRequest('GET', 'https://example.com/users/42');
        $request = $this->adapter->convert($psr);

        self::assertSame('/users/42', $request->path);
    }

    #[Test]
    public function convertPreservesQueryString(): void
    {
        $psr = new ServerRequest('GET', 'https://example.com?page=2&sort=name');
        $request = $this->adapter->convert($psr);

        self::assertSame('page=2&sort=name', $request->queryString);
    }

    #[Test]
    public function convertPreservesHeaders(): void
    {
        $psr = new ServerRequest('GET', 'https://example.com');
        $psr = $psr->withHeader('X-Custom', 'value1');
        $request = $this->adapter->convert($psr);

        self::assertTrue($request->headers->has('X-Custom'));
        self::assertSame('value1', $request->headers->first('X-Custom'));
    }

    #[Test]
    public function convertPreservesBody(): void
    {
        $factory = new Psr17Factory();
        $psr = new ServerRequest('POST', 'https://example.com', body: $factory->createStream('{"key":"val"}'));
        $request = $this->adapter->convert($psr);

        self::assertSame('{"key":"val"}', $request->body);
    }

    #[Test]
    public function convertPreservesQueryParams(): void
    {
        $psr = new ServerRequest('GET', 'https://example.com')
            ->withQueryParams(['page' => '1', 'limit' => '10']);
        $request = $this->adapter->convert($psr);

        self::assertSame('1', $request->query['page']);
        self::assertSame('10', $request->query['limit']);
    }

    #[Test]
    public function convertPreservesParsedBody(): void
    {
        $psr = new ServerRequest('POST', 'https://example.com')
            ->withParsedBody(['name' => 'John']);
        $request = $this->adapter->convert($psr);

        self::assertSame('John', $request->post['name']);
    }

    #[Test]
    public function convertHandlesNullParsedBody(): void
    {
        $psr = new ServerRequest('GET', 'https://example.com');
        $request = $this->adapter->convert($psr);

        self::assertSame([], $request->post);
    }

    #[Test]
    public function convertPreservesCookies(): void
    {
        $psr = new ServerRequest('GET', 'https://example.com')
            ->withCookieParams(['session' => 'abc123']);
        $request = $this->adapter->convert($psr);

        self::assertSame('abc123', $request->cookies['session']);
    }

    #[Test]
    public function convertPreservesAttributes(): void
    {
        $psr = new ServerRequest('GET', 'https://example.com')
            ->withAttribute('user_id', 42);
        $request = $this->adapter->convert($psr);

        self::assertSame(42, $request->attributes['user_id']);
    }

    #[Test]
    public function convertPreservesProtocolVersion(): void
    {
        $psr = new ServerRequest('GET', 'https://example.com')
            ->withProtocolVersion('2.0');
        $request = $this->adapter->convert($psr);

        self::assertSame('2.0', $request->protocolVersion);
    }
}
