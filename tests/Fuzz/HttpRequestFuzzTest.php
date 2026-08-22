<?php

declare(strict_types=1);

namespace Pulsar\Tests\Fuzz;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\HeaderValidator;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Message\Uri;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use ValueError;

#[CoversClass(ServerRequest::class)]
#[CoversClass(HeaderValidator::class)]
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
            '/path%00/segment',
            "\x00/",
            "/\x00",
            "/path/\x00file.php",
        ];

        foreach ($paths as $path) {
            $request = new ServerRequest(method: 'GET', uri: $path);
            $target = $request->getRequestTarget();
            self::assertNotSame('', $target, "Request target should not be empty for path: {$path}");
        }
    }

    #[Test]
    public function malformedContentTypeHeadersAreHandled(): void
    {
        // Garbage that is still a legal RFC 7230 field-value: it must be
        // carried, not rejected, and must not derail content negotiation.
        $contentTypes = [
            '',
            'invalid',
            'application/',
            '/json',
            'application/json; charset=',
            'application/json; charset=utf-8; boundary=',
            str_repeat('application/json; ', 500),
        ];

        foreach ($contentTypes as $contentType) {
            $request = new ServerRequest(
                method: 'POST',
                uri: '/',
                headers: ['Content-Type' => $contentType],
                body: '{"key":"value"}',
            );

            $expected = str_contains($contentType, 'application/json') ? ['key' => 'value'] : [];

            self::assertSame($contentType, $request->getHeaderLine('Content-Type'));
            self::assertSame($expected, $request->json());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function headerValuesOutsideFieldContent(): iterable
    {
        yield 'response splitting' => ["text/html\r\nX-Injected: value"];
        yield 'bare carriage return' => ["text/html\rX-Injected: value"];
        yield 'bare line feed' => ["text/html\nX-Injected: value"];
        yield 'nul terminator' => ["application/json\x00"];
        yield 'early body' => ["text/html\r\n\r\n<script>alert(1)</script>"];
    }

    #[DataProvider('headerValuesOutsideFieldContent')]
    #[Test]
    public function headerValuesOutsideFieldContentAreRejectedByTheConstructor(string $value): void
    {
        // `withHeader()` has always refused these. The constructor used to
        // wave them through, so a request object could hold a header the
        // mutators would not accept.
        $this->expectException(InvalidArgumentException::class);

        new ServerRequest(
            method: 'POST',
            uri: '/',
            headers: ['Content-Type' => $value],
            body: '{"key":"value"}',
        );
    }

    #[Test]
    public function headerNamesOutsideTheTokenAlphabetAreRejectedByTheConstructor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ServerRequest(method: 'GET', uri: '/', headers: ["X-Foo\r\nX-Injected" => 'value']);
    }

    #[Test]
    public function hostileServerEntriesNeverBecomeHeaders(): void
    {
        // Ingress drops instead of throwing: `fromGlobals()` runs before the
        // pipeline exists, so an exception here has nowhere to go.
        $request = ServerRequest::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/',
                'HTTP_ACCEPT' => 'text/html',
                'HTTP_X_EVIL' => "ok\r\nX-Injected: yes",
                'HTTP_X_NUL' => "ok\0",
                // Undecodable underscore placement: no hyphen-separated
                // field-name mangles to any of these.
                'HTTP__X_FORWARDED_PROTO' => 'https',
                'HTTP_X__FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_PROTO_' => 'https',
                // Not a CGI meta-variable name at all.
                'HTTP_X-FORWARDED-PROTO' => 'https',
                'HTTP_X FOO' => 'bar',
                'HTTP_' => 'bar',
            ],
            get: [],
            post: [],
            cookies: [],
            files: [],
        );

        self::assertSame('text/html', $request->getHeaderLine('Accept'));
        self::assertFalse($request->hasHeader('X-Evil'));
        self::assertFalse($request->hasHeader('X-Nul'));
        self::assertFalse($request->hasHeader('X-Forwarded-Proto'));
        self::assertFalse($request->hasHeader('-X-Forwarded-Proto'));
        self::assertFalse($request->hasHeader('X--Forwarded-Proto'));
        self::assertFalse($request->hasHeader('X-Forwarded-Proto-'));
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
            self::assertNotSame('', $target, 'Request target should not be empty for unicode path');
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
            self::assertNotSame('', $target, "Request target should not be empty for query: {$query}");
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
            self::assertSame('GET', $request->getMethod());
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
            // json() returns array — verify it completed without throwing
            self::addToAssertionCount(1);
        }
    }
}
