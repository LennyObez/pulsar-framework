<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psr7Bridge\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psr7Bridge\Adapter\PulsarToPsr7Request;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;

final class PulsarToPsr7RequestTest extends TestCase
{
    private PulsarToPsr7Request $adapter;

    protected function setUp(): void
    {
        $this->adapter = new PulsarToPsr7Request();
    }

    #[Test]
    public function convertPreservesHttpMethod(): void
    {
        $request = $this->createRequest(method: Method::PUT);
        $psr = $this->adapter->convert($request);

        self::assertSame('PUT', $psr->getMethod());
    }

    #[Test]
    public function convertPreservesUri(): void
    {
        $request = $this->createRequest(uri: 'https://example.com/api/users?page=1');
        $psr = $this->adapter->convert($request);

        self::assertSame('https://example.com/api/users?page=1', (string) $psr->getUri());
    }

    #[Test]
    public function convertPreservesHeaders(): void
    {
        $request = $this->createRequest(headers: new HeaderBag(['Accept' => 'text/html']));
        $psr = $this->adapter->convert($request);

        self::assertTrue($psr->hasHeader('Accept'));
        self::assertSame('text/html', $psr->getHeaderLine('Accept'));
    }

    #[Test]
    public function convertPreservesBody(): void
    {
        $request = $this->createRequest(body: '{"data":true}');
        $psr = $this->adapter->convert($request);

        self::assertSame('{"data":true}', (string) $psr->getBody());
    }

    #[Test]
    public function convertPreservesQueryParams(): void
    {
        $request = $this->createRequest(query: ['page' => '2', 'sort' => 'name']);
        $psr = $this->adapter->convert($request);

        $params = $psr->getQueryParams();
        self::assertSame('2', $params['page']);
        self::assertSame('name', $params['sort']);
    }

    #[Test]
    public function convertPreservesCookies(): void
    {
        $request = $this->createRequest(cookies: ['token' => 'xyz']);
        $psr = $this->adapter->convert($request);

        self::assertSame('xyz', $psr->getCookieParams()['token']);
    }

    #[Test]
    public function convertPreservesPostAsNullWhenEmpty(): void
    {
        $request = $this->createRequest();
        $psr = $this->adapter->convert($request);

        self::assertNull($psr->getParsedBody());
    }

    #[Test]
    public function convertPreservesPostWhenNonEmpty(): void
    {
        $request = $this->createRequest(post: ['name' => 'Jane']);
        $psr = $this->adapter->convert($request);

        self::assertSame(['name' => 'Jane'], $psr->getParsedBody());
    }

    #[Test]
    public function convertPreservesAttributes(): void
    {
        $request = $this->createRequest(attributes: ['route' => 'home']);
        $psr = $this->adapter->convert($request);

        self::assertSame('home', $psr->getAttribute('route'));
    }

    #[Test]
    public function convertPreservesProtocolVersion(): void
    {
        $request = $this->createRequest(protocolVersion: '2.0');
        $psr = $this->adapter->convert($request);

        self::assertSame('2.0', $psr->getProtocolVersion());
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $attributes
     */
    private function createRequest(
        Method $method = Method::GET,
        string $uri = 'https://example.com',
        HeaderBag $headers = new HeaderBag(),
        string $body = '',
        array $query = [],
        array $post = [],
        array $cookies = [],
        array $attributes = [],
        string $protocolVersion = '1.1',
    ): Request {
        return new Request(
            method: $method,
            uri: $uri,
            path: '/',
            queryString: '',
            headers: $headers,
            body: $body,
            query: $query,
            post: $post,
            cookies: $cookies,
            server: [],
            attributes: $attributes,
            protocolVersion: $protocolVersion,
        );
    }
}
