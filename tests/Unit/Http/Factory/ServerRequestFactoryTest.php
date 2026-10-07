<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Factory;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Factory\ServerRequestFactory;
use Pulsar\Http\Message\Uri;

final class ServerRequestFactoryTest extends TestCase
{
    private ServerRequestFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ServerRequestFactory();
    }

    #[Test]
    public function creates_request_with_string_uri(): void
    {
        $request = $this->factory->createServerRequest('GET', 'https://example.com/path?q=1');

        self::assertSame('GET', $request->getMethod());
        self::assertSame('example.com', $request->getUri()->getHost());
        self::assertSame('/path', $request->getUri()->getPath());
        self::assertSame('q=1', $request->getUri()->getQuery());
    }

    #[Test]
    public function creates_request_with_uri_instance(): void
    {
        $uri = Uri::fromString('https://api.example.com/v1');

        $request = $this->factory->createServerRequest('POST', $uri);

        self::assertSame('POST', $request->getMethod());
        self::assertSame('api.example.com', $request->getUri()->getHost());
    }

    #[Test]
    public function passes_server_params(): void
    {
        $request = $this->factory->createServerRequest('GET', '/test', [
            'REMOTE_ADDR' => '192.168.1.1',
            'SERVER_PORT' => 8080,
        ]);

        $params = $request->getServerParams();

        self::assertSame('192.168.1.1', $params['REMOTE_ADDR']);
        self::assertSame(8080, $params['SERVER_PORT']);
    }

    #[Test]
    public function creates_request_with_empty_server_params(): void
    {
        $request = $this->factory->createServerRequest('DELETE', '/resource');

        self::assertSame('DELETE', $request->getMethod());
        self::assertSame([], $request->getServerParams());
    }

    #[Test]
    public function preserves_various_http_methods(): void
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'] as $method) {
            $request = $this->factory->createServerRequest($method, '/test');
            self::assertSame($method, $request->getMethod());
        }
    }
}
