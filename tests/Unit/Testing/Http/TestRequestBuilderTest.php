<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Testing\Http\TestRequestBuilder;

#[CoversClass(TestRequestBuilder::class)]
final class TestRequestBuilderTest extends TestCase
{
    #[Test]
    public function get_creates_get_request(): void
    {
        $request = TestRequestBuilder::get('/api/users')->build();

        self::assertSame('GET', $request->getMethod());
        self::assertSame('/api/users', $request->getUri()->getPath());
    }

    #[Test]
    public function post_creates_post_request(): void
    {
        $request = TestRequestBuilder::post('/api/users')->build();

        self::assertSame('POST', $request->getMethod());
    }

    #[Test]
    public function put_creates_put_request(): void
    {
        $request = TestRequestBuilder::put('/api/users/1')->build();

        self::assertSame('PUT', $request->getMethod());
    }

    #[Test]
    public function patch_creates_patch_request(): void
    {
        $request = TestRequestBuilder::patch('/api/users/1')->build();

        self::assertSame('PATCH', $request->getMethod());
    }

    #[Test]
    public function delete_creates_delete_request(): void
    {
        $request = TestRequestBuilder::delete('/api/users/1')->build();

        self::assertSame('DELETE', $request->getMethod());
    }

    #[Test]
    public function with_header_adds_header(): void
    {
        $request = TestRequestBuilder::get('/api')
            ->withHeader('Accept', 'application/json')
            ->build();

        self::assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    #[Test]
    public function with_headers_adds_multiple_headers(): void
    {
        $request = TestRequestBuilder::get('/api')
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Custom' => 'test',
            ])
            ->build();

        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('test', $request->getHeaderLine('X-Custom'));
    }

    #[Test]
    public function with_token_adds_bearer_auth(): void
    {
        $request = TestRequestBuilder::get('/api')
            ->withToken('my-secret-token')
            ->build();

        self::assertSame('Bearer my-secret-token', $request->getHeaderLine('Authorization'));
    }

    #[Test]
    public function with_json_sets_body_and_content_type(): void
    {
        $request = TestRequestBuilder::post('/api/users')
            ->withJson(['name' => 'John', 'email' => 'john@test.com'])
            ->build();

        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $body = (string) $request->getBody();
        self::assertStringContainsString('"name":"John"', $body);

        $parsedBody = $request->getParsedBody();
        self::assertIsArray($parsedBody);
        self::assertSame('John', $parsedBody['name']);
    }

    #[Test]
    public function with_body_sets_raw_body(): void
    {
        $request = TestRequestBuilder::post('/api/upload')
            ->withBody('<xml>data</xml>', 'application/xml')
            ->build();

        self::assertSame('application/xml', $request->getHeaderLine('Content-Type'));
        self::assertSame('<xml>data</xml>', (string) $request->getBody());
    }

    #[Test]
    public function with_query_adds_query_params(): void
    {
        $request = TestRequestBuilder::get('/api/search')
            ->withQuery(['q' => 'test', 'page' => 2])
            ->build();

        $params = $request->getQueryParams();
        self::assertSame('test', $params['q']);
        self::assertSame(2, $params['page']);
    }

    #[Test]
    public function with_cookies_adds_cookie_params(): void
    {
        $request = TestRequestBuilder::get('/api')
            ->withCookies(['session' => 'abc123'])
            ->build();

        $cookies = $request->getCookieParams();
        self::assertSame('abc123', $cookies['session']);
    }

    #[Test]
    public function with_server_params_sets_server_params(): void
    {
        $request = TestRequestBuilder::get('/api')
            ->withServerParams(['REMOTE_ADDR' => '127.0.0.1'])
            ->build();

        $params = $request->getServerParams();
        self::assertSame('127.0.0.1', $params['REMOTE_ADDR']);
    }

    #[Test]
    public function fluent_builder_chains(): void
    {
        $request = TestRequestBuilder::post('/api/users')
            ->withHeader('Accept', 'application/json')
            ->withToken('token')
            ->withJson(['name' => 'Test'])
            ->withCookies(['session' => 'abc'])
            ->build();

        self::assertSame('POST', $request->getMethod());
        self::assertSame('Bearer token', $request->getHeaderLine('Authorization'));
        self::assertSame('abc', $request->getCookieParams()['session']);
    }
}
