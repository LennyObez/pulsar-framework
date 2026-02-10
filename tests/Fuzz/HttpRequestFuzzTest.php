<?php

declare(strict_types=1);

namespace Pulsar\Tests\Fuzz;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Message\Uri;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use ValueError;

#[CoversClass(ServerRequest::class)]
#[CoversClass(Request::class)]
#[CoversClass(Uri::class)]
#[Group('fuzz')]
final class HttpRequestFuzzTest extends TestCase
{
    #[Test]
    public function randomHttpMethodsDoNotCrash(): void
    {
        $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD', 'TRACE', 'CONNECT', 'PROPFIND', 'MKCOL', 'LOCK', 'UNLOCK', '', 'INVALID', 'G3T', '!@#$%^', "GET\r\nX-Injected: true"];

        foreach ($methods as $method) {
            $request = new ServerRequest(method: $method, uri: '/');
            self::assertSame($method, $request->getMethod());
        }
    }

    #[Test]
    public function oversizedHeadersDoNotCrash(): void
    {
        $largeValue = str_repeat('A', 8192);
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['X-Large-Header' => $largeValue],
        );

        self::assertSame($largeValue, $request->getHeaderLine('X-Large-Header'));
    }

    #[Test]
    public function nullBytesInUriAreHandled(): void
    {
        $paths = [
            "/path\x00/segment",
            "/path%00/segment",
            "\x00/",
            "/\x00",
            "/path/\x00file.php",
        ];

        foreach ($paths as $path) {
            $request = new ServerRequest(method: 'GET', uri: $path);
            $target = $request->getRequestTarget();
            self::assertIsString($target);
        }
    }

    #[Test]
    public function malformedContentTypeHeadersAreHandled(): void
    {
        $contentTypes = [
            '',
            'invalid',
            'application/',
            '/json',
            "application/json\x00",
            'application/json; charset=',
            'application/json; charset=utf-8; boundary=',
            str_repeat('application/json; ', 500),
            "text/html\r\nX-Injected: value",
        ];

        foreach ($contentTypes as $contentType) {
            $request = new ServerRequest(
                method: 'POST',
                uri: '/',
                headers: ['Content-Type' => $contentType],
                body: '{"key":"value"}',
            );

            $json = $request->json();
            self::assertIsArray($json);
        }
    }

    #[Test]
    public function unicodeInPathSegmentsIsHandled(): void
    {
        $paths = [
            '/路径/段落',
            '/パス/セグメント',
            '/путь/сегмент',
            '/مسیر/بخش',
            '/🔥/🎉/🚀',
            '/café/résumé',
            '/path/' . str_repeat('あ', 500),
        ];

        foreach ($paths as $path) {
            $request = new ServerRequest(method: 'GET', uri: $path);
            $target = $request->getRequestTarget();
            self::assertIsString($target);
        }
    }

    #[Test]
    public function randomQueryStringsWithSpecialCharsAreHandled(): void
    {
        $queries = [
            'key=' . str_repeat('a', 10000),
            'key=value&' . str_repeat('key2=value2&', 1000),
            "key=val\x00ue",
            'key=<script>alert(1)</script>',
            'key=' . urlencode("'; DROP TABLE users; --"),
            'key=%00%01%02%03%04%05',
            'key=value&key=value2&key=value3',
            '&&&&&',
            '=====',
            'key[0]=a&key[1]=b&key[2]=c',
        ];

        foreach ($queries as $query) {
            $request = new ServerRequest(
                method: 'GET',
                uri: '/?' . $query,
            );

            $target = $request->getRequestTarget();
            self::assertIsString($target);
        }
    }

    #[Test]
    public function manyHeadersDoNotCrash(): void
    {
        $headers = [];
        for ($i = 0; $i < 200; $i++) {
            $headers['X-Header-' . $i] = 'value-' . $i;
        }

        $request = new ServerRequest(method: 'GET', uri: '/', headers: $headers);
        self::assertCount(200, $request->getHeaders());
    }

    #[Test]
    public function emptyAndWhitespaceInputsAreHandled(): void
    {
        $inputs = ['', ' ', "\t", "\n", "\r\n", "\0", '   '];

        foreach ($inputs as $input) {
            $request = new ServerRequest(method: 'GET', uri: $input === '' ? '/' : $input);
            self::assertIsString($request->getMethod());
        }
    }

    #[Test]
    public function pulsarRequestHandlesRandomizedServerArrays(): void
    {
        $serverArrays = [
            [],
            ['REQUEST_METHOD' => 42],
            ['REQUEST_URI' => null],
            ['SERVER_PROTOCOL' => 'HTTP/3.0'],
            ['SERVER_PROTOCOL' => 'NOT_HTTP'],
            ['HTTPS' => 'on', 'HTTP_HOST' => 'example.com:443'],
            ['HTTP_HOST' => 'example.com:99999'],
            ['HTTP_HOST' => "example.com\r\nX-Injected: true"],
        ];

        foreach ($serverArrays as $server) {
            $request = Request::fromGlobals(
                get: [],
                post: [],
                cookies: [],
                server: $server,
            );
            self::assertInstanceOf(Request::class, $request);
        }

        // Empty REQUEST_METHOD throws ValueError from Method enum — test that separately
        $this->expectException(ValueError::class);
        (void) Request::fromGlobals(
            get: [],
            post: [],
            cookies: [],
            server: ['REQUEST_METHOD' => ''],
        );
    }

    #[Test]
    public function requestBodyWithRandomBytesIsHandled(): void
    {
        $bodies = [
            random_bytes(1),
            random_bytes(256),
            random_bytes(4096),
            str_repeat("\x00", 100),
            "\xFF\xFE" . random_bytes(50),
        ];

        foreach ($bodies as $body) {
            $request = new ServerRequest(
                method: 'POST',
                uri: '/',
                headers: ['Content-Type' => 'application/json'],
                body: $body,
            );

            $json = $request->json();
            self::assertIsArray($json);
        }
    }
}
