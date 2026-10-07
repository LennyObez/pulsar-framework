<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Message\Response as MessageResponse;
use Pulsar\Http\Method;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Runtime\Bridge\PsrBridge;

#[CoversClass(PsrBridge::class)]
final class PsrBridgeTest extends TestCase
{
    private PsrBridge $bridge;

    protected function setUp(): void
    {
        $this->bridge = new PsrBridge(
            static function (int $status, string $body, array $headers): ResponseInterface {
                $response = new MessageResponse(
                    statusCode: $status,
                    headers: $headers,
                    body: $body,
                );

                return $response;
            },
        );
    }

    #[Test]
    public function it_converts_psr_request_method_and_path(): void
    {
        $psrRequest = $this->createPsrRequest(
            method: 'POST',
            path: '/api/users',
            query: '',
        );

        $request = $this->bridge->toPulsarRequest($psrRequest);

        self::assertSame(Method::POST, $request->method);
        self::assertSame('/api/users', $request->path);
    }

    #[Test]
    public function it_converts_psr_request_query_params(): void
    {
        $psrRequest = $this->createPsrRequest(
            method: 'GET',
            path: '/search',
            query: 'q=test&page=2',
            queryParams: ['q' => 'test', 'page' => '2'],
        );

        $request = $this->bridge->toPulsarRequest($psrRequest);

        self::assertSame(['q' => 'test', 'page' => '2'], $request->query);
        self::assertSame('q=test&page=2', $request->queryString);
    }

    #[Test]
    public function it_converts_psr_request_headers(): void
    {
        $psrRequest = $this->createPsrRequest(
            method: 'GET',
            path: '/',
            query: '',
            headers: [
                'Content-Type' => ['application/json'],
                'Accept' => ['text/html'],
            ],
        );

        $request = $this->bridge->toPulsarRequest($psrRequest);

        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertSame('text/html', $request->header('Accept'));
    }

    #[Test]
    public function it_converts_psr_request_body(): void
    {
        $psrRequest = $this->createPsrRequest(
            method: 'POST',
            path: '/submit',
            query: '',
            body: '{"name":"pulsar"}',
        );

        $request = $this->bridge->toPulsarRequest($psrRequest);

        self::assertSame('{"name":"pulsar"}', $request->body);
    }

    #[Test]
    public function it_converts_psr_request_cookies(): void
    {
        $psrRequest = $this->createPsrRequest(
            method: 'GET',
            path: '/',
            query: '',
            cookies: ['session_id' => 'abc123', 'theme' => 'dark'],
        );

        $request = $this->bridge->toPulsarRequest($psrRequest);

        self::assertSame(['session_id' => 'abc123', 'theme' => 'dark'], $request->cookies);
    }

    #[Test]
    public function it_handles_empty_body(): void
    {
        $psrRequest = $this->createPsrRequest(
            method: 'GET',
            path: '/',
            query: '',
            body: '',
        );

        $request = $this->bridge->toPulsarRequest($psrRequest);

        self::assertSame('', $request->body);
    }

    #[Test]
    public function it_joins_multi_value_headers_with_comma(): void
    {
        $psrRequest = $this->createPsrRequest(
            method: 'GET',
            path: '/',
            query: '',
            headers: [
                'Accept' => ['text/html', 'application/json', 'text/plain'],
            ],
        );

        $request = $this->bridge->toPulsarRequest($psrRequest);

        self::assertSame('text/html, application/json, text/plain', $request->header('Accept'));
    }

    #[Test]
    public function it_converts_pulsar_response_status(): void
    {
        $response = new Response(
            body: 'Created',
            status: ResponseStatus::Created,
        );

        $psrResponse = $this->bridge->toPsrResponse($response);

        self::assertSame(201, $psrResponse->getStatusCode());
    }

    #[Test]
    public function it_converts_pulsar_response_body(): void
    {
        $response = new Response(
            body: '{"id":1}',
            status: ResponseStatus::OK,
        );

        $psrResponse = $this->bridge->toPsrResponse($response);

        self::assertSame('{"id":1}', (string) $psrResponse->getBody());
    }

    #[Test]
    public function it_converts_pulsar_response_headers(): void
    {
        $response = new Response(
            body: '',
            status: ResponseStatus::OK,
            headers: new HeaderBag([
                'Content-Type' => 'application/json',
                'X-Request-Id' => 'req-42',
            ]),
        );

        $psrResponse = $this->bridge->toPsrResponse($response);

        self::assertTrue($psrResponse->hasHeader('Content-Type'));
        self::assertTrue($psrResponse->hasHeader('X-Request-Id'));
    }

    #[Test]
    public function it_converts_psr_request_protocol_version(): void
    {
        $psrRequest = $this->createPsrRequest(
            method: 'GET',
            path: '/',
            query: '',
            protocolVersion: '2.0',
        );

        $request = $this->bridge->toPulsarRequest($psrRequest);

        self::assertSame('2.0', $request->protocolVersion);
    }

    #[Test]
    public function it_converts_psr_request_parsed_body_as_post(): void
    {
        $psrRequest = $this->createPsrRequest(
            method: 'POST',
            path: '/form',
            query: '',
            parsedBody: ['username' => 'admin', 'password' => 'secret'],
        );

        $request = $this->bridge->toPulsarRequest($psrRequest);

        self::assertSame(['username' => 'admin', 'password' => 'secret'], $request->post);
    }

    #[Test]
    public function it_converts_psr_request_with_empty_path_to_root(): void
    {
        $psrRequest = $this->createPsrRequest(
            method: 'GET',
            path: '',
            query: '',
        );

        $request = $this->bridge->toPulsarRequest($psrRequest);

        self::assertSame('/', $request->path);
    }

    /**
     * @param array<string, list<string>> $headers
     * @param array<string, string> $queryParams
     * @param array<string, string> $cookies
     * @param array<string, mixed>|null $parsedBody
     */
    private function createPsrRequest(
        string $method,
        string $path,
        string $query,
        array $headers = [],
        string $body = '',
        array $queryParams = [],
        array $cookies = [],
        string $protocolVersion = '1.1',
        ?array $parsedBody = null,
    ): ServerRequestInterface {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $uri->method('getQuery')->willReturn($query);
        $uri->method('__toString')->willReturn($path . ($query !== '' ? '?' . $query : ''));

        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn($queryParams);
        $request->method('getHeaders')->willReturn($headers);
        $request->method('getBody')->willReturn($stream);
        $request->method('getCookieParams')->willReturn($cookies);
        $request->method('getServerParams')->willReturn([]);
        $request->method('getProtocolVersion')->willReturn($protocolVersion);
        $request->method('getParsedBody')->willReturn($parsedBody);

        return $request;
    }
}
