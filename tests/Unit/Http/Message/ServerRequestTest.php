<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Message;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\BodyTooLargeException;
use Pulsar\Http\Message\BufferStream;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Message\Stream;
use Pulsar\Http\Message\UploadedFile;
use Pulsar\Http\Message\Uri;
use stdClass;

#[CoversClass(ServerRequest::class)]
#[CoversClass(BodyTooLargeException::class)]
final class ServerRequestTest extends TestCase
{
    // ── Constructor ────────────────────────────────────────────────────

    #[Test]
    public function defaultConstructorCreatesGetRequest(): void
    {
        $request = new ServerRequest();

        self::assertSame('GET', $request->getMethod());
        self::assertSame('1.1', $request->getProtocolVersion());
        self::assertSame([], $request->getServerParams());
        self::assertSame([], $request->getCookieParams());
        self::assertSame([], $request->getQueryParams());
        self::assertSame([], $request->getUploadedFiles());
        self::assertNull($request->getParsedBody());
        self::assertSame([], $request->getAttributes());
    }

    #[Test]
    public function constructorAcceptsAllParameters(): void
    {
        $uri = new Uri(scheme: 'https', host: 'example.com', path: '/api');
        $body = Stream::create('test body');
        $uploaded = new UploadedFile(streamOrFile: Stream::create('file content'), size: 12, error: UPLOAD_ERR_OK);

        $request = new ServerRequest(
            method: 'POST',
            uri: $uri,
            headers: ['Content-Type' => 'application/json', 'Accept' => ['text/html', 'application/json']],
            body: $body,
            protocolVersion: '2.0',
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
            cookieParams: ['session' => 'abc'],
            queryParams: ['page' => '1'],
            uploadedFiles: [$uploaded],
            parsedBody: ['name' => 'test'],
            attributes: ['user_id' => 42],
        );

        self::assertSame('POST', $request->getMethod());
        self::assertSame($uri, $request->getUri());
        self::assertSame($body, $request->getBody());
        self::assertSame('2.0', $request->getProtocolVersion());
        self::assertSame(['REMOTE_ADDR' => '127.0.0.1'], $request->getServerParams());
        self::assertSame(['session' => 'abc'], $request->getCookieParams());
        self::assertSame(['page' => '1'], $request->getQueryParams());
        self::assertSame([$uploaded], $request->getUploadedFiles());
        self::assertSame(['name' => 'test'], $request->getParsedBody());
        self::assertSame(['user_id' => 42], $request->getAttributes());
    }

    #[Test]
    public function constructorAcceptsStringUri(): void
    {
        $request = new ServerRequest(uri: 'https://example.com/path?q=1');

        self::assertSame('https', $request->getUri()->getScheme());
        self::assertSame('example.com', $request->getUri()->getHost());
        self::assertSame('/path', $request->getUri()->getPath());
        self::assertSame('q=1', $request->getUri()->getQuery());
    }

    #[Test]
    public function constructorAcceptsStringBody(): void
    {
        $request = new ServerRequest(body: 'hello body');

        self::assertSame('hello body', (string) $request->getBody());
    }

    #[Test]
    public function constructorSetsHostHeaderFromUriWhenNotProvided(): void
    {
        $request = new ServerRequest(uri: 'https://example.com:8443/');

        self::assertTrue($request->hasHeader('Host'));
        self::assertSame('example.com:8443', $request->getHeaderLine('Host'));
    }

    #[Test]
    public function constructorPreservesExplicitHostHeader(): void
    {
        $request = new ServerRequest(
            uri: 'https://example.com/',
            headers: ['Host' => 'custom-host.com'],
        );

        self::assertSame('custom-host.com', $request->getHeaderLine('Host'));
    }

    #[Test]
    public function constructorDoesNotSetHostWhenUriHasNoHost(): void
    {
        $request = new ServerRequest(uri: '/path/only');

        self::assertFalse($request->hasHeader('Host'));
    }

    #[Test]
    public function constructorNormalizesHeaderNames(): void
    {
        $request = new ServerRequest(headers: ['Content-Type' => 'text/plain']);

        self::assertTrue($request->hasHeader('content-type'));
        self::assertTrue($request->hasHeader('Content-Type'));
        self::assertTrue($request->hasHeader('CONTENT-TYPE'));
    }

    // ── PSR-7 MessageInterface ────────────────────────────────────────

    #[Test]
    public function withProtocolVersionReturnsNewInstance(): void
    {
        $original = new ServerRequest();
        $new = $original->withProtocolVersion('2.0');

        self::assertNotSame($original, $new);
        self::assertSame('1.1', $original->getProtocolVersion());
        self::assertSame('2.0', $new->getProtocolVersion());
    }

    #[Test]
    public function withProtocolVersionReturnsSameInstanceWhenUnchanged(): void
    {
        $request = new ServerRequest(protocolVersion: '1.1');

        self::assertSame($request, $request->withProtocolVersion('1.1'));
    }

    #[Test]
    public function getHeadersPreservesOriginalCase(): void
    {
        $request = new ServerRequest(headers: [
            'Content-Type' => 'text/html',
            'X-Custom-Header' => ['value1', 'value2'],
        ]);

        $headers = $request->getHeaders();

        self::assertArrayHasKey('Content-Type', $headers);
        self::assertArrayHasKey('X-Custom-Header', $headers);
        self::assertSame(['text/html'], $headers['Content-Type']);
        self::assertSame(['value1', 'value2'], $headers['X-Custom-Header']);
    }

    #[Test]
    public function hasHeaderIsCaseInsensitive(): void
    {
        $request = new ServerRequest(headers: ['X-Token' => 'abc']);

        self::assertTrue($request->hasHeader('X-Token'));
        self::assertTrue($request->hasHeader('x-token'));
        self::assertFalse($request->hasHeader('X-Missing'));
    }

    #[Test]
    public function getHeaderReturnsCaseInsensitiveValues(): void
    {
        $request = new ServerRequest(headers: ['Accept' => ['text/html', 'application/json']]);

        self::assertSame(['text/html', 'application/json'], $request->getHeader('accept'));
        self::assertSame([], $request->getHeader('Missing'));
    }

    #[Test]
    public function getHeaderLineJoinsValues(): void
    {
        $request = new ServerRequest(headers: ['Accept' => ['text/html', 'application/json']]);

        self::assertSame('text/html, application/json', $request->getHeaderLine('Accept'));
        self::assertSame('', $request->getHeaderLine('Missing'));
    }

    #[Test]
    public function withHeaderReplacesExisting(): void
    {
        $request = new ServerRequest(headers: ['Content-Type' => 'text/html']);
        $new = $request->withHeader('Content-Type', 'application/json');

        self::assertSame('text/html', $request->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $new->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function withHeaderAcceptsArrayValue(): void
    {
        $request = new ServerRequest();
        $new = $request->withHeader('Accept', ['text/html', 'text/xml']);

        self::assertSame(['text/html', 'text/xml'], $new->getHeader('Accept'));
    }

    #[Test]
    public function withAddedHeaderAppendsToExisting(): void
    {
        $request = new ServerRequest(headers: ['Accept' => 'text/html']);
        $new = $request->withAddedHeader('Accept', 'application/json');

        self::assertSame(['text/html', 'application/json'], $new->getHeader('Accept'));
    }

    #[Test]
    public function withAddedHeaderCreatesNewWhenMissing(): void
    {
        $request = new ServerRequest();
        $new = $request->withAddedHeader('X-New', 'value');

        self::assertSame(['value'], $new->getHeader('X-New'));
    }

    #[Test]
    public function withAddedHeaderAcceptsArrayValue(): void
    {
        $request = new ServerRequest();
        $new = $request->withAddedHeader('Accept', ['text/html', 'text/xml']);

        self::assertSame(['text/html', 'text/xml'], $new->getHeader('Accept'));
    }

    #[Test]
    public function withoutHeaderRemovesHeader(): void
    {
        $request = new ServerRequest(headers: ['X-Remove' => 'value', 'Keep' => 'yes']);
        $new = $request->withoutHeader('X-Remove');

        self::assertTrue($request->hasHeader('X-Remove'));
        self::assertFalse($new->hasHeader('X-Remove'));
        self::assertTrue($new->hasHeader('Keep'));
    }

    #[Test]
    public function withoutHeaderReturnsSameInstanceWhenMissing(): void
    {
        $request = new ServerRequest();

        self::assertSame($request, $request->withoutHeader('Non-Existent'));
    }

    #[Test]
    public function withBodyReturnsNewInstance(): void
    {
        $original = new ServerRequest(body: 'original');
        $newBody = Stream::create('new body');
        $new = $original->withBody($newBody);

        self::assertNotSame($original, $new);
        self::assertSame('original', (string) $original->getBody());
        self::assertSame('new body', (string) $new->getBody());
    }

    // ── PSR-7 RequestInterface ────────────────────────────────────────

    #[Test]
    public function getRequestTargetReturnPathAndQuery(): void
    {
        $request = new ServerRequest(uri: 'https://example.com/api/users?page=1');

        self::assertSame('/api/users?page=1', $request->getRequestTarget());
    }

    #[Test]
    public function getRequestTargetReturnsSlashWhenPathEmpty(): void
    {
        $request = new ServerRequest(uri: new Uri(scheme: 'https', host: 'example.com'));

        self::assertSame('/', $request->getRequestTarget());
    }

    #[Test]
    public function getRequestTargetReturnsExplicitTarget(): void
    {
        $request = new ServerRequest()->withRequestTarget('*');

        self::assertSame('*', $request->getRequestTarget());
    }

    #[Test]
    public function withRequestTargetReturnsNewInstance(): void
    {
        $request = new ServerRequest();
        $new = $request->withRequestTarget('/custom');

        self::assertNotSame($request, $new);
        self::assertSame('/custom', $new->getRequestTarget());
    }

    #[Test]
    public function withMethodReturnsNewInstance(): void
    {
        $request = new ServerRequest(method: 'GET');
        $new = $request->withMethod('POST');

        self::assertNotSame($request, $new);
        self::assertSame('GET', $request->getMethod());
        self::assertSame('POST', $new->getMethod());
    }

    #[Test]
    public function withUriUpdatesHostHeader(): void
    {
        $request = new ServerRequest(uri: 'https://old.com/');
        $newUri = Uri::fromString('https://new.com:9090/');
        $new = $request->withUri($newUri);

        self::assertSame('new.com:9090', $new->getHeaderLine('Host'));
    }

    #[Test]
    public function withUriPreservesHostWhenFlagSet(): void
    {
        $request = new ServerRequest(
            uri: 'https://old.com/',
            headers: ['Host' => 'old.com'],
        );
        $newUri = Uri::fromString('https://new.com/');
        $new = $request->withUri($newUri, preserveHost: true);

        self::assertSame('old.com', $new->getHeaderLine('Host'));
    }

    #[Test]
    public function withUriSetsHostWhenPreserveHostButNoHostHeader(): void
    {
        $request = new ServerRequest(uri: '/path');
        self::assertFalse($request->hasHeader('Host'));

        $newUri = Uri::fromString('https://new.com:8080/');
        $new = $request->withUri($newUri, preserveHost: true);

        self::assertSame('new.com:8080', $new->getHeaderLine('Host'));
    }

    #[Test]
    public function withUriDoesNotSetHostWhenNewUriHasNoHost(): void
    {
        $request = new ServerRequest(uri: '/path');
        $newUri = Uri::fromString('/another-path');
        $new = $request->withUri($newUri);

        self::assertFalse($new->hasHeader('Host'));
    }

    // ── PSR-7 ServerRequestInterface ──────────────────────────────────

    #[Test]
    public function withCookieParamsReturnsNewInstance(): void
    {
        $request = new ServerRequest();
        $new = $request->withCookieParams(['session' => 'xyz']);

        self::assertNotSame($request, $new);
        self::assertSame([], $request->getCookieParams());
        self::assertSame(['session' => 'xyz'], $new->getCookieParams());
    }

    #[Test]
    public function withQueryParamsReturnsNewInstance(): void
    {
        $request = new ServerRequest();
        $new = $request->withQueryParams(['page' => '2']);

        self::assertNotSame($request, $new);
        self::assertSame([], $request->getQueryParams());
        self::assertSame(['page' => '2'], $new->getQueryParams());
    }

    #[Test]
    public function withUploadedFilesReturnsNewInstance(): void
    {
        $file = new UploadedFile(streamOrFile: Stream::create('data'), size: 4, error: UPLOAD_ERR_OK);
        $request = new ServerRequest();
        $new = $request->withUploadedFiles([$file]);

        self::assertNotSame($request, $new);
        self::assertSame([], $request->getUploadedFiles());
        self::assertCount(1, $new->getUploadedFiles());
    }

    #[Test]
    public function withParsedBodyAcceptsArray(): void
    {
        $request = new ServerRequest();
        $new = $request->withParsedBody(['name' => 'test']);

        self::assertNotSame($request, $new);
        self::assertNull($request->getParsedBody());
        self::assertSame(['name' => 'test'], $new->getParsedBody());
    }

    #[Test]
    public function withParsedBodyAcceptsObject(): void
    {
        $obj = new stdClass();
        $obj->name = 'test';
        $request = new ServerRequest();
        $new = $request->withParsedBody($obj);

        self::assertSame($obj, $new->getParsedBody());
    }

    #[Test]
    public function withParsedBodyAcceptsNull(): void
    {
        $request = new ServerRequest(parsedBody: ['data' => 'value']);
        $new = $request->withParsedBody(null);

        self::assertNull($new->getParsedBody());
    }

    #[Test]
    public function withParsedBodyRejectsInvalidType(): void
    {
        $request = new ServerRequest();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Parsed body must be array, object, or null.');
        /** @var array<mixed>|object|null $invalidBody Intentionally passing a string to test rejection */
        $invalidBody = json_decode('"invalid string"');
        $_ = $request->withParsedBody($invalidBody);
    }

    #[Test]
    public function withAttributeAddsAttribute(): void
    {
        $request = new ServerRequest();
        $new = $request->withAttribute('user_id', 42);

        self::assertSame(42, $new->getAttribute('user_id'));
        self::assertNull($request->getAttribute('user_id'));
    }

    #[Test]
    public function getAttributeReturnsDefaultWhenMissing(): void
    {
        $request = new ServerRequest();

        self::assertNull($request->getAttribute('missing'));
        self::assertSame('default', $request->getAttribute('missing', 'default'));
    }

    #[Test]
    public function withoutAttributeRemovesAttribute(): void
    {
        $request = new ServerRequest(attributes: ['key' => 'value', 'other' => 'data']);
        $new = $request->withoutAttribute('key');

        self::assertNull($new->getAttribute('key'));
        self::assertSame('data', $new->getAttribute('other'));
    }

    #[Test]
    public function withoutAttributeReturnsSameInstanceWhenMissing(): void
    {
        $request = new ServerRequest();

        self::assertSame($request, $request->withoutAttribute('non-existent'));
    }

    #[Test]
    public function getAttributesReturnsAllAttributes(): void
    {
        $request = new ServerRequest(attributes: ['a' => 1, 'b' => 2]);

        self::assertSame(['a' => 1, 'b' => 2], $request->getAttributes());
    }

    // ── Pulsar Convenience Methods ────────────────────────────────────

    #[Test]
    public function headerReturnsFirstValue(): void
    {
        $request = new ServerRequest(headers: ['Accept' => ['text/html', 'application/json']]);

        self::assertSame('text/html', $request->header('Accept'));
    }

    #[Test]
    public function headerReturnsDefaultWhenMissing(): void
    {
        $request = new ServerRequest();

        self::assertNull($request->header('Missing'));
        self::assertSame('fallback', $request->header('Missing', 'fallback'));
    }

    #[Test]
    public function queryReturnsParameter(): void
    {
        $request = new ServerRequest(queryParams: ['page' => '1', 'limit' => '10']);

        self::assertSame('1', $request->query('page'));
        self::assertSame('10', $request->query('limit'));
        self::assertNull($request->query('missing'));
        self::assertSame('default', $request->query('missing', 'default'));
    }

    #[Test]
    public function postReturnsFromParsedBody(): void
    {
        $request = new ServerRequest(parsedBody: ['username' => 'alice']);

        self::assertSame('alice', $request->post('username'));
        self::assertNull($request->post('missing'));
        self::assertSame('default', $request->post('missing', 'default'));
    }

    #[Test]
    public function postReturnsDefaultWhenParsedBodyIsNotArray(): void
    {
        $request = new ServerRequest(parsedBody: new stdClass());

        self::assertNull($request->post('any'));
        self::assertSame('default', $request->post('any', 'default'));
    }

    #[Test]
    public function postReturnsDefaultWhenParsedBodyIsNull(): void
    {
        $request = new ServerRequest(parsedBody: null);

        self::assertNull($request->post('any'));
    }

    #[Test]
    public function cookieReturnsValue(): void
    {
        $request = new ServerRequest(cookieParams: ['session' => 'abc123']);

        self::assertSame('abc123', $request->cookie('session'));
        self::assertNull($request->cookie('missing'));
    }

    #[Test]
    public function serverReturnsValue(): void
    {
        $request = new ServerRequest(serverParams: ['REMOTE_ADDR' => '10.0.0.1']);

        self::assertSame('10.0.0.1', $request->server('REMOTE_ADDR'));
        self::assertNull($request->server('missing'));
    }

    #[Test]
    public function attributeReturnsValue(): void
    {
        $request = new ServerRequest(attributes: ['user_id' => 42]);

        self::assertSame(42, $request->attribute('user_id'));
        self::assertNull($request->attribute('missing'));
        self::assertSame('default', $request->attribute('missing', 'default'));
    }

    #[Test]
    public function routeParamReturnsAttribute(): void
    {
        $request = new ServerRequest(attributes: ['id' => '123']);

        self::assertSame('123', $request->routeParam('id'));
        self::assertNull($request->routeParam('missing'));
        self::assertSame('default', $request->routeParam('missing', 'default'));
    }

    // ── JSON / Input ─────────────────────────────────────────────────

    #[Test]
    public function jsonDecodesBodyWithJsonContentType(): void
    {
        $request = new ServerRequest(
            headers: ['Content-Type' => 'application/json'],
            body: '{"name":"Alice","age":30}',
        );

        self::assertSame(['name' => 'Alice', 'age' => 30], $request->json());
    }

    #[Test]
    public function jsonReturnsEmptyArrayForNonJsonContentType(): void
    {
        $request = new ServerRequest(
            headers: ['Content-Type' => 'text/html'],
            body: '{"name":"Alice"}',
        );

        self::assertSame([], $request->json());
    }

    #[Test]
    public function jsonReturnsEmptyArrayForNoContentType(): void
    {
        $request = new ServerRequest(body: '{"name":"Alice"}');

        self::assertSame([], $request->json());
    }

    #[Test]
    public function jsonReturnsEmptyArrayForEmptyBody(): void
    {
        $request = new ServerRequest(
            headers: ['Content-Type' => 'application/json'],
            body: '',
        );

        self::assertSame([], $request->json());
    }

    #[Test]
    public function jsonReturnsEmptyArrayForInvalidJson(): void
    {
        $request = new ServerRequest(
            headers: ['Content-Type' => 'application/json'],
            body: '{not valid json}',
        );

        self::assertSame([], $request->json());
    }

    #[Test]
    public function jsonReturnsEmptyArrayForNonArrayDecoded(): void
    {
        $request = new ServerRequest(
            headers: ['Content-Type' => 'application/json'],
            body: '"just a string"',
        );

        self::assertSame([], $request->json());
    }

    #[Test]
    public function jsonIsMemoized(): void
    {
        $request = new ServerRequest(
            headers: ['Content-Type' => 'application/json'],
            body: '{"key":"value"}',
        );

        $first = $request->json();
        $second = $request->json();

        self::assertSame($first, $second);
    }

    #[Test]
    public function allMergesQueryPostAndJson(): void
    {
        $request = new ServerRequest(
            headers: ['Content-Type' => 'application/json'],
            body: '{"json_key":"json_val"}',
            queryParams: ['q_key' => 'q_val'],
            parsedBody: ['post_key' => 'post_val'],
        );

        $all = $request->all();
        self::assertSame('q_val', $all['q_key']);
        self::assertSame('post_val', $all['post_key']);
        self::assertSame('json_val', $all['json_key']);
    }

    #[Test]
    public function allJsonOverridesPostOverridesQuery(): void
    {
        $request = new ServerRequest(
            headers: ['Content-Type' => 'application/json'],
            body: '{"key":"from_json"}',
            queryParams: ['key' => 'from_query'],
            parsedBody: ['key' => 'from_post'],
        );

        self::assertSame('from_json', $request->all()['key']);
    }

    #[Test]
    public function allHandlesNonArrayParsedBody(): void
    {
        $request = new ServerRequest(
            queryParams: ['q' => '1'],
            parsedBody: new stdClass(),
        );

        $all = $request->all();
        self::assertSame('1', $all['q']);
    }

    #[Test]
    public function inputReturnsFromMergedData(): void
    {
        $request = new ServerRequest(queryParams: ['name' => 'Alice']);

        self::assertSame('Alice', $request->input('name'));
        self::assertNull($request->input('missing'));
        self::assertSame('default', $request->input('missing', 'default'));
    }

    #[Test]
    public function hasChecksAllKeysExist(): void
    {
        $request = new ServerRequest(queryParams: ['a' => '1', 'b' => '2']);

        self::assertTrue($request->has('a'));
        self::assertTrue($request->has('a', 'b'));
        self::assertFalse($request->has('a', 'c'));
    }

    #[Test]
    public function filledChecksKeysExistAndNotEmpty(): void
    {
        $request = new ServerRequest(queryParams: ['name' => 'Alice', 'empty' => '', 'null_val' => null]);

        self::assertTrue($request->filled('name'));
        self::assertFalse($request->filled('empty'));
        self::assertFalse($request->filled('null_val'));
        self::assertFalse($request->filled('missing'));
    }

    #[Test]
    public function onlyReturnsSubset(): void
    {
        $request = new ServerRequest(queryParams: ['a' => '1', 'b' => '2', 'c' => '3']);

        self::assertSame(['a' => '1', 'b' => '2'], $request->only('a', 'b'));
    }

    #[Test]
    public function exceptExcludesKeys(): void
    {
        $request = new ServerRequest(queryParams: ['a' => '1', 'b' => '2', 'c' => '3']);

        self::assertSame(['a' => '1', 'b' => '2'], $request->except('c'));
    }

    // ── Utility Methods ──────────────────────────────────────────────

    #[Test]
    public function wantsJsonReturnsTrueWhenAcceptContainsJson(): void
    {
        $request = new ServerRequest(headers: ['Accept' => 'application/json']);
        self::assertTrue($request->wantsJson());
    }

    #[Test]
    public function wantsJsonReturnsFalseWhenAcceptDoesNotContainJson(): void
    {
        $request = new ServerRequest(headers: ['Accept' => 'text/html']);
        self::assertFalse($request->wantsJson());
    }

    #[Test]
    public function wantsJsonReturnsFalseWhenNoAcceptHeader(): void
    {
        $request = new ServerRequest();
        self::assertFalse($request->wantsJson());
    }

    #[Test]
    public function isAjaxReturnsTrueForXhr(): void
    {
        $request = new ServerRequest(headers: ['X-Requested-With' => 'XMLHttpRequest']);
        self::assertTrue($request->isAjax());
    }

    #[Test]
    public function isAjaxReturnsFalseForNormalRequest(): void
    {
        $request = new ServerRequest();
        self::assertFalse($request->isAjax());
    }

    #[Test]
    public function isSecureReturnsTrueForHttps(): void
    {
        $request = new ServerRequest(uri: 'https://example.com/');
        self::assertTrue($request->isSecure());
    }

    #[Test]
    public function isSecureReturnsFalseForHttp(): void
    {
        $request = new ServerRequest(uri: 'http://example.com/');
        self::assertFalse($request->isSecure());
    }

    /**
     * F2.9: a load balancer that terminates TLS leaves PHP with a
     * plain `http://` scheme. The framework only honours
     * `X-Forwarded-Proto: https` when the immediate hop is a
     * trusted proxy; an arbitrary client cannot smuggle the
     * header in to fake a secure request.
     */
    #[Test]
    public function isSecureHonorsXForwardedProtoFromTrustedProxy(): void
    {
        $request = new ServerRequest(
            uri: 'http://example.com/',
            headers: ['X-Forwarded-Proto' => 'https'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $proxy = new \Pulsar\Http\TrustedProxy(['10.0.0.0/8']);

        self::assertTrue($request->isSecure($proxy));
    }

    /**
     * F2.9: without a trusted-proxy chain, the X-Forwarded-Proto
     * header is ignored — the framework will not promote a
     * plaintext request to "secure" based on a client-supplied
     * header.
     */
    #[Test]
    public function isSecureIgnoresXForwardedProtoWithoutTrustedProxy(): void
    {
        $request = new ServerRequest(
            uri: 'http://example.com/',
            headers: ['X-Forwarded-Proto' => 'https'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        self::assertFalse($request->isSecure());
    }

    /**
     * F2.9: when REMOTE_ADDR is outside the trusted-proxy CIDR,
     * the forwarded header is rejected. A real client behind a
     * legitimate LB cannot impersonate the LB's `proto=https`
     * by talking directly to PHP-FPM.
     */
    #[Test]
    public function isSecureRejectsXForwardedProtoFromUntrustedSource(): void
    {
        $request = new ServerRequest(
            uri: 'http://example.com/',
            headers: ['X-Forwarded-Proto' => 'https'],
            serverParams: ['REMOTE_ADDR' => '198.51.100.5'],
        );

        $proxy = new \Pulsar\Http\TrustedProxy(['10.0.0.0/8']);

        self::assertFalse($request->isSecure($proxy));
    }

    /**
     * F2.9: RFC 7239 `Forwarded: proto=https` is honoured under
     * the same trusted-proxy gate as `X-Forwarded-Proto`.
     */
    #[Test]
    public function isSecureHonorsRfc7239ForwardedHeader(): void
    {
        $request = new ServerRequest(
            uri: 'http://example.com/',
            headers: ['Forwarded' => 'for=192.0.2.43;proto=https;by=10.0.0.1'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $proxy = new \Pulsar\Http\TrustedProxy(['10.0.0.0/8']);

        self::assertTrue($request->isSecure($proxy));
    }

    #[Test]
    public function preferredContentTypeReturnsFirstType(): void
    {
        $request = new ServerRequest(headers: ['Accept' => 'application/json, text/html;q=0.9']);
        self::assertSame('application/json', $request->preferredContentType());
    }

    #[Test]
    public function preferredContentTypeStripsQuality(): void
    {
        $request = new ServerRequest(headers: ['Accept' => 'text/html;q=0.9']);
        self::assertSame('text/html', $request->preferredContentType());
    }

    #[Test]
    public function preferredContentTypeReturnsNullWithoutAccept(): void
    {
        $request = new ServerRequest();
        self::assertNull($request->preferredContentType());
    }

    // ── bufferBody ───────────────────────────────────────────────────

    #[Test]
    public function bufferBodyCreatesRewindableStream(): void
    {
        $request = new ServerRequest(body: 'original content');
        $buffered = $request->bufferBody();

        self::assertNotSame($request, $buffered);
        self::assertInstanceOf(BufferStream::class, $buffered->getBody());
        self::assertSame('original content', (string) $buffered->getBody());
    }

    #[Test]
    public function bufferBodyThrowsWhenSizeExceedsLimit(): void
    {
        $request = new ServerRequest(body: 'too large content');

        $this->expectException(BodyTooLargeException::class);
        (void) $request->bufferBody(maxBytes: 5);
    }

    #[Test]
    public function bufferBodyThrowsWhenStreamReadExceedsLimit(): void
    {
        // Use a stream without a known size
        $resource = fopen('php://temp', 'r+b');
        self::assertNotFalse($resource);
        fwrite($resource, str_repeat('x', 100));
        rewind($resource);
        $stream = new Stream($resource);

        $request = new ServerRequest(body: $stream);

        $this->expectException(BodyTooLargeException::class);
        (void) $request->bufferBody(maxBytes: 50);
    }

    // ── withAttributes ───────────────────────────────────────────────

    #[Test]
    public function withAttributesMergesMultiple(): void
    {
        $request = new ServerRequest(attributes: ['existing' => 'value']);
        $new = $request->withAttributes(['a' => 1, 'b' => 2]);

        self::assertSame('value', $new->getAttribute('existing'));
        self::assertSame(1, $new->getAttribute('a'));
        self::assertSame(2, $new->getAttribute('b'));
    }

    // ── fromGlobals ──────────────────────────────────────────────────

    #[Test]
    public function fromGlobalsCreatesFromServerData(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/users?page=1',
            'SERVER_PROTOCOL' => 'HTTP/2.0',
            'HTTP_HOST' => 'example.com:8080',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '42',
            'SERVER_PORT' => '8080',
            'HTTPS' => 'on',
        ];

        $request = ServerRequest::fromGlobals(
            server: $server,
            get: ['page' => '1'],
            post: ['name' => 'Alice'],
            cookies: ['session' => 'xyz'],
        );

        self::assertSame('POST', $request->getMethod());
        self::assertSame('https', $request->getUri()->getScheme());
        self::assertSame('example.com', $request->getUri()->getHost());
        self::assertSame(8080, $request->getUri()->getPort());
        self::assertSame('/api/users', $request->getUri()->getPath());
        self::assertSame('page=1', $request->getUri()->getQuery());
        self::assertSame('2.0', $request->getProtocolVersion());
        self::assertSame(['page' => '1'], $request->getQueryParams());
        self::assertSame(['name' => 'Alice'], $request->getParsedBody());
        self::assertSame(['session' => 'xyz'], $request->getCookieParams());
    }

    #[Test]
    public function fromGlobalsDefaultsForMissingKeys(): void
    {
        $request = ServerRequest::fromGlobals(
            server: [],
            get: [],
            post: [],
            cookies: [],
        );

        self::assertSame('GET', $request->getMethod());
        self::assertSame('1.1', $request->getProtocolVersion());
        self::assertSame('http', $request->getUri()->getScheme());
    }

    #[Test]
    public function fromGlobalsParsesBracketedIpv6Host(): void
    {
        // FR-23: a bracketed IPv6 authority "[::1]:8080" must split into host
        // "[::1]" and port 8080. The old explode(":") split on every colon,
        // yielding host "[" and port 0.
        $request = ServerRequest::fromGlobals(
            server: ['HTTP_HOST' => '[::1]:8080'],
            get: [],
            post: [],
            cookies: [],
        );

        self::assertSame('[::1]', $request->getUri()->getHost());
        self::assertSame(8080, $request->getUri()->getPort());
    }

    #[Test]
    public function fromGlobalsParsesBracketedIpv6HostWithoutPort(): void
    {
        // FR-23: a bracketed IPv6 literal with no port keeps the whole address.
        $request = ServerRequest::fromGlobals(
            server: ['HTTP_HOST' => '[2001:db8::1]'],
            get: [],
            post: [],
            cookies: [],
        );

        self::assertSame('[2001:db8::1]', $request->getUri()->getHost());
    }

    #[Test]
    public function fromGlobalsUsesServerNameWhenNoHttpHost(): void
    {
        $request = ServerRequest::fromGlobals(
            server: ['SERVER_NAME' => 'myapp.local', 'SERVER_PORT' => '443'],
            get: [],
            post: [],
            cookies: [],
        );

        self::assertSame('myapp.local', $request->getUri()->getHost());
        self::assertSame(443, $request->getUri()->getPort());
    }

    #[Test]
    public function fromGlobalsExtractsContentHeaders(): void
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '100',
            'CONTENT_MD5' => 'abc123',
            'HTTP_ACCEPT' => 'text/html',
        ];

        $request = ServerRequest::fromGlobals(server: $server, get: [], post: [], cookies: []);

        self::assertTrue($request->hasHeader('CONTENT-TYPE'));
        self::assertTrue($request->hasHeader('CONTENT-LENGTH'));
        self::assertTrue($request->hasHeader('CONTENT-MD5'));
        self::assertTrue($request->hasHeader('ACCEPT'));
    }

    #[Test]
    public function fromGlobalsNormalizesUploadedFiles(): void
    {
        $files = [
            'avatar' => [
                'tmp_name' => '/tmp/phpXXXX',
                'size' => 1234,
                'error' => UPLOAD_ERR_OK,
                'name' => 'photo.jpg',
                'type' => 'image/jpeg',
            ],
        ];

        $request = ServerRequest::fromGlobals(
            server: [],
            get: [],
            post: [],
            cookies: [],
            files: $files,
        );

        $uploaded = $request->getUploadedFiles();
        self::assertArrayHasKey('avatar', $uploaded);
        self::assertInstanceOf(UploadedFile::class, $uploaded['avatar']);
    }

    #[Test]
    public function fromGlobalsNormalizesMultipleUploadedFiles(): void
    {
        $files = [
            'photos' => [
                'tmp_name' => ['/tmp/php1', '/tmp/php2'],
                'size' => [100, 200],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'name' => ['a.jpg', 'b.jpg'],
                'type' => ['image/jpeg', 'image/jpeg'],
            ],
        ];

        $request = ServerRequest::fromGlobals(
            server: [],
            get: [],
            post: [],
            cookies: [],
            files: $files,
        );

        $uploaded = $request->getUploadedFiles();
        self::assertArrayHasKey('photos', $uploaded);
        self::assertIsArray($uploaded['photos']);
        self::assertCount(2, $uploaded['photos']);
    }

    #[Test]
    public function fromGlobalsPreservesUploadedFileInstances(): void
    {
        $file = new UploadedFile(
            streamOrFile: Stream::create('data'),
            size: 4,
            error: UPLOAD_ERR_OK,
        );

        $request = ServerRequest::fromGlobals(
            server: [],
            get: [],
            post: [],
            cookies: [],
            files: ['doc' => $file],
        );

        $uploaded = $request->getUploadedFiles();
        self::assertSame($file, $uploaded['doc']);
    }

    #[Test]
    public function fromGlobalsHandlesEmptyPost(): void
    {
        $request = ServerRequest::fromGlobals(
            server: [],
            get: [],
            post: [],
            cookies: [],
        );

        self::assertNull($request->getParsedBody());
    }

    #[Test]
    public function fromGlobalsSkipsNonStringServerValues(): void
    {
        $server = [
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_ARRAY_VALUE' => ['not', 'a', 'string'],
            'CONTENT_TYPE' => 42,
        ];

        $request = ServerRequest::fromGlobals(server: $server, get: [], post: [], cookies: []);

        self::assertTrue($request->hasHeader('Accept'));
        self::assertFalse($request->hasHeader('Array-Value'));
        self::assertFalse($request->hasHeader('Content-Type'));
    }

    #[Test]
    public function fromGlobalsHandlesRequestUriWithoutQuery(): void
    {
        $server = ['REQUEST_URI' => '/simple-path'];

        $request = ServerRequest::fromGlobals(server: $server, get: [], post: [], cookies: []);

        self::assertSame('/simple-path', $request->getUri()->getPath());
        self::assertSame('', $request->getUri()->getQuery());
    }

    #[Test]
    public function fromGlobalsHandlesNonStringRequestMethod(): void
    {
        $server = ['REQUEST_METHOD' => 42];

        $request = ServerRequest::fromGlobals(server: $server, get: [], post: [], cookies: []);

        self::assertSame('GET', $request->getMethod());
    }

    #[Test]
    public function fromGlobalsHandlesNonStringRequestUri(): void
    {
        $server = ['REQUEST_URI' => 42];

        $request = ServerRequest::fromGlobals(server: $server, get: [], post: [], cookies: []);

        self::assertSame('/', $request->getUri()->getPath());
    }

    #[Test]
    public function fromGlobalsHandlesHttpsOff(): void
    {
        $server = ['HTTPS' => 'off'];

        $request = ServerRequest::fromGlobals(server: $server, get: [], post: [], cookies: []);

        self::assertSame('http', $request->getUri()->getScheme());
    }
}
