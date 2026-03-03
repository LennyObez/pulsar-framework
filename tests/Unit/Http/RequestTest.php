<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Exception\BodyTooLargeException;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;

use function base64_encode;
use function str_repeat;
use function strlen;

#[CoversClass(Request::class)]
#[CoversClass(Method::class)]
final class RequestTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $headers = new HeaderBag(['Content-Type' => 'application/json']);

        $request = new Request(
            method: Method::POST,
            uri: '/api/users?sort=name',
            path: '/api/users',
            queryString: 'sort=name',
            headers: $headers,
            body: '{"name":"test"}',
            query: ['sort' => 'name'],
            post: ['name' => 'test'],
            protocolVersion: '1.1',
        );

        self::assertSame(Method::POST, $request->method);
        self::assertSame('/api/users?sort=name', $request->uri);
        self::assertSame('/api/users', $request->path);
        self::assertSame('sort=name', $request->queryString);
        self::assertSame($headers, $request->headers);
        self::assertSame('{"name":"test"}', $request->body);
        self::assertSame(['sort' => 'name'], $request->query);
        self::assertSame(['name' => 'test'], $request->post);
        self::assertSame('1.1', $request->protocolVersion);
    }

    #[Test]
    public function queryMethodReturnsParameter(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            query: ['page' => '1', 'limit' => '10'],
        );

        self::assertSame('1', $request->query('page'));
        self::assertSame('10', $request->query('limit'));
        self::assertNull($request->query('missing'));
        self::assertSame('default', $request->query('missing', 'default'));
    }

    #[Test]
    public function postMethodReturnsParameter(): void
    {
        $request = new Request(
            method: Method::POST,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            post: ['username' => 'john'],
        );

        self::assertSame('john', $request->post('username'));
        self::assertNull($request->post('missing'));
    }

    #[Test]
    public function cookieMethodReturnsValue(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            cookies: ['session' => 'abc123'],
        );

        self::assertSame('abc123', $request->cookie('session'));
        self::assertNull($request->cookie('missing'));
    }

    #[Test]
    public function serverMethodReturnsValue(): void
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

        self::assertSame('127.0.0.1', $request->server('REMOTE_ADDR'));
        self::assertNull($request->server('MISSING'));
    }

    #[Test]
    public function attributeMethodReturnsValue(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            attributes: ['user_id' => 42],
        );

        self::assertSame(42, $request->attribute('user_id'));
        self::assertNull($request->attribute('missing'));
    }

    #[Test]
    public function headerMethodReturnsFirstValue(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Content-Type' => 'application/json']),
            body: '',
        );

        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertNull($request->header('Missing'));
        self::assertSame('default', $request->header('Missing', 'default'));
    }

    #[Test]
    public function withAttributeReturnsNewRequestWithAttribute(): void
    {
        $original = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );

        $new = $original->withAttribute('key', 'value');

        self::assertNotSame($original, $new);
        self::assertNull($original->attribute('key'));
        self::assertSame('value', $new->attribute('key'));
    }

    #[Test]
    public function withoutAttributeReturnsNewRequestWithoutAttribute(): void
    {
        $original = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            attributes: ['key' => 'value'],
        );

        $new = $original->withoutAttribute('key');

        self::assertNotSame($original, $new);
        self::assertSame('value', $original->attribute('key'));
        self::assertNull($new->attribute('key'));
    }

    #[Test]
    public function isAjaxReturnsTrueForXhrRequest(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['X-Requested-With' => 'XMLHttpRequest']),
            body: '',
        );

        self::assertTrue($request->isAjax());
    }

    #[Test]
    public function isAjaxReturnsFalseForNormalRequest(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );

        self::assertFalse($request->isAjax());
    }

    #[Test]
    public function isSecureReturnsTrueForHttps(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            server: ['HTTPS' => 'on'],
        );

        self::assertTrue($request->isSecure());
    }

    #[Test]
    public function isSecureReturnsFalseForHttp(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            server: ['HTTPS' => 'off'],
        );

        self::assertFalse($request->isSecure());
    }

    #[Test]
    public function preferredContentTypeReturnsFirstAcceptType(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Accept' => 'application/json, text/html;q=0.9']),
            body: '',
        );

        self::assertSame('application/json', $request->preferredContentType());
    }

    #[Test]
    public function preferredContentTypeStripsQualityValue(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Accept' => 'text/html;q=0.9']),
            body: '',
        );

        self::assertSame('text/html', $request->preferredContentType());
    }

    #[Test]
    public function preferredContentTypeReturnsNullIfNoAcceptHeader(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );

        self::assertNull($request->preferredContentType());
    }

    #[Test]
    public function jsonDecodesBodyWhenContentTypeIsJson(): void
    {
        $request = new Request(
            method: Method::POST,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Content-Type' => 'application/json']),
            body: '{"name":"John","age":30}',
        );

        self::assertSame(['name' => 'John', 'age' => 30], $request->json());
    }

    #[Test]
    public function jsonReturnsEmptyArrayForNonJsonContentType(): void
    {
        $request = new Request(
            method: Method::POST,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Content-Type' => 'text/html']),
            body: '{"name":"John"}',
        );

        self::assertSame([], $request->json());
    }

    #[Test]
    public function jsonReturnsEmptyArrayForInvalidJson(): void
    {
        $request = new Request(
            method: Method::POST,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Content-Type' => 'application/json']),
            body: '{invalid json}',
        );

        self::assertSame([], $request->json());
    }

    #[Test]
    public function jsonReturnsEmptyArrayForEmptyBody(): void
    {
        $request = new Request(
            method: Method::POST,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Content-Type' => 'application/json']),
            body: '',
        );

        self::assertSame([], $request->json());
    }

    #[Test]
    public function allMergesQueryPostAndJson(): void
    {
        $request = new Request(
            method: Method::POST,
            uri: '/?page=1',
            path: '/',
            queryString: 'page=1',
            headers: new HeaderBag(['Content-Type' => 'application/json']),
            body: '{"name":"John"}',
            query: ['page' => '1'],
            post: ['token' => 'abc'],
        );

        $all = $request->all();
        self::assertSame('1', $all['page']);
        self::assertSame('abc', $all['token']);
        self::assertSame('John', $all['name']);
    }

    #[Test]
    public function allJsonOverridesPostOverridesQuery(): void
    {
        $request = new Request(
            method: Method::POST,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Content-Type' => 'application/json']),
            body: '{"key":"from_json"}',
            query: ['key' => 'from_query'],
            post: ['key' => 'from_post'],
        );

        self::assertSame('from_json', $request->all()['key']);
    }

    #[Test]
    public function inputReturnsValueFromMergedData(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            query: ['name' => 'John'],
        );

        self::assertSame('John', $request->input('name'));
        self::assertNull($request->input('missing'));
        self::assertSame('default', $request->input('missing', 'default'));
    }

    #[Test]
    public function hasReturnsTrueWhenAllKeysExist(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            query: ['name' => 'John', 'email' => 'john@test.com'],
        );

        self::assertTrue($request->has('name'));
        self::assertTrue($request->has('name', 'email'));
        self::assertFalse($request->has('name', 'missing'));
    }

    #[Test]
    public function filledReturnsTrueWhenAllKeysExistAndNotEmpty(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            query: ['name' => 'John', 'empty' => '', 'present' => 'yes'],
        );

        self::assertTrue($request->filled('name'));
        self::assertFalse($request->filled('empty'));
        self::assertFalse($request->filled('missing'));
        self::assertTrue($request->filled('name', 'present'));
        self::assertFalse($request->filled('name', 'empty'));
    }

    #[Test]
    public function onlyReturnsSubset(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            query: ['name' => 'John', 'email' => 'john@test.com', 'extra' => 'data'],
        );

        self::assertSame(
            ['name' => 'John', 'email' => 'john@test.com'],
            $request->only('name', 'email'),
        );
    }

    #[Test]
    public function exceptExcludesKeys(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            query: ['name' => 'John', 'email' => 'john@test.com', 'extra' => 'data'],
        );

        self::assertSame(
            ['name' => 'John', 'email' => 'john@test.com'],
            $request->except('extra'),
        );
    }

    #[Test]
    public function wantsJsonReturnsTrueWhenAcceptContainsJson(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Accept' => 'application/json']),
            body: '',
        );

        self::assertTrue($request->wantsJson());
    }

    #[Test]
    public function wantsJsonReturnsFalseWhenAcceptDoesNotContainJson(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Accept' => 'text/html']),
            body: '',
        );

        self::assertFalse($request->wantsJson());
    }

    #[Test]
    public function wantsJsonReturnsFalseWhenNoAcceptHeader(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );

        self::assertFalse($request->wantsJson());
    }

    #[Test]
    public function isSecureReturnsFalseWhenNoHttpsKey(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            server: [],
        );

        self::assertFalse($request->isSecure());
    }

    #[Test]
    public function jsonReturnsEmptyArrayForNonArrayDecoded(): void
    {
        $request = new Request(
            method: Method::POST,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Content-Type' => 'application/json']),
            body: '"just a string"',
        );

        self::assertSame([], $request->json());
    }

    #[Test]
    public function filledReturnsFalseForNullValue(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            query: ['name' => null],
        );

        self::assertFalse($request->filled('name'));
    }

    #[Test]
    public function fromGlobalsCreatesRequestFromServerData(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/users?page=1',
            'QUERY_STRING' => 'page=1',
            'SERVER_PROTOCOL' => 'HTTP/2.0',
            'HTTP_HOST' => 'example.com',
            'HTTP_CONTENT_TYPE' => 'application/json',
        ];
        $get = ['page' => '1'];
        $post = ['name' => 'Alice'];
        $cookies = ['session' => 'xyz'];

        $request = Request::fromGlobals(
            get: $get,
            post: $post,
            cookies: $cookies,
            server: $server,
        );

        self::assertSame(Method::POST, $request->method);
        self::assertSame('/api/users?page=1', $request->uri);
        self::assertSame('/api/users', $request->path);
        self::assertSame('page=1', $request->queryString);
        self::assertSame('2.0', $request->protocolVersion);
        self::assertSame('1', $request->query('page'));
        self::assertSame('Alice', $request->post('name'));
        self::assertSame('xyz', $request->cookie('session'));
    }

    #[Test]
    public function fromGlobalsDefaultsForMissingServerKeys(): void
    {
        $request = Request::fromGlobals(
            get: [],
            post: [],
            cookies: [],
            server: [],
        );

        self::assertSame(Method::GET, $request->method);
        self::assertSame('/', $request->uri);
        self::assertSame('/', $request->path);
        self::assertSame('', $request->queryString);
        self::assertSame('1.1', $request->protocolVersion);
    }

    // --- withAttributes() batch method ---

    #[Test]
    public function withAttributesMergesMultipleAttributes(): void
    {
        $original = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            attributes: ['existing' => 'value'],
        );

        $new = $original->withAttributes(['a' => 1, 'b' => 2]);

        self::assertNotSame($original, $new);
        self::assertSame('value', $new->attribute('existing'));
        self::assertSame(1, $new->attribute('a'));
        self::assertSame(2, $new->attribute('b'));
    }

    #[Test]
    public function withAttributesOverwritesExistingKeys(): void
    {
        $original = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            attributes: ['key' => 'old'],
        );

        $new = $original->withAttributes(['key' => 'new']);

        self::assertSame('old', $original->attribute('key'));
        self::assertSame('new', $new->attribute('key'));
    }

    #[Test]
    public function withAttributesEmptyArrayReturnsClone(): void
    {
        $original = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            attributes: ['key' => 'value'],
        );

        $new = $original->withAttributes([]);

        self::assertNotSame($original, $new);
        self::assertSame('value', $new->attribute('key'));
    }

    // --- json() memoization ---

    #[Test]
    public function jsonReturnsSameResultOnRepeatedCalls(): void
    {
        $request = new Request(
            method: Method::POST,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Content-Type' => 'application/json']),
            body: '{"name":"Alice","age":25}',
        );

        $first = $request->json();
        $second = $request->json();

        self::assertSame($first, $second);
        self::assertSame(['name' => 'Alice', 'age' => 25], $first);
    }

    #[Test]
    public function jsonMemoizationDoesNotLeakBetweenInstances(): void
    {
        $request1 = new Request(
            method: Method::POST,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Content-Type' => 'application/json']),
            body: '{"key":"one"}',
        );

        $request2 = new Request(
            method: Method::POST,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Content-Type' => 'application/json']),
            body: '{"key":"two"}',
        );

        self::assertSame(['key' => 'one'], $request1->json());
        self::assertSame(['key' => 'two'], $request2->json());
    }

    #[Test]
    public function fromGlobalsRejectsContentLengthExceedingCap(): void
    {
        // The Content-Length fast path lets us reject without ever touching
        // the body stream — no need to fabricate a real over-sized payload.
        $this->expectException(BodyTooLargeException::class);

        (void) Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/upload',
                'CONTENT_LENGTH' => '20000',
            ],
            maxBodyBytes: 1024,
        );
    }

    #[Test]
    public function readBoundedBodyAcceptsBodyAtExactlyTheCap(): void
    {
        $body = Request::readBoundedBody($this->dataStream(str_repeat('x', 1024)), 1024, 1024);

        self::assertSame(1024, strlen($body));
    }

    #[Test]
    public function readBoundedBodyRejectsBodyOneByteOverTheCap(): void
    {
        $this->expectException(BodyTooLargeException::class);

        Request::readBoundedBody($this->dataStream(str_repeat('x', 1025)), 1024, 1025);
    }

    #[Test]
    public function readBoundedBodyHandlesZeroLimitAsUnlimited(): void
    {
        $body = Request::readBoundedBody($this->dataStream(str_repeat('y', 16_384)), 0, 0);

        self::assertSame(16_384, strlen($body));
    }

    #[Test]
    public function readBoundedBodyReturnsEmptyForUnreadableStream(): void
    {
        $body = Request::readBoundedBody(
            '/this/path/does/not/exist/anywhere/' . uniqid(),
            1024,
            0,
        );

        self::assertSame('', $body);
    }

    /**
     * Build a `data://` stream URL whose contents are exactly `$contents`.
     *
     * `data://` is a static, in-memory stream wrapper: PHP can `fopen` it
     * like any other file and read it via `fread`, but there is no temp
     * file to clean up afterwards. That keeps the test hermetic and
     * sidesteps the `unlink()`-in-tests path-traversal heuristic from the
     * security scanner — there is simply nothing to delete.
     */
    private function dataStream(string $contents): string
    {
        return 'data://application/octet-stream;base64,' . base64_encode($contents);
    }
}

#[CoversClass(Method::class)]
final class MethodEnumTest extends TestCase
{
    #[Test]
    public function isSafeReturnsCorrectValues(): void
    {
        self::assertTrue(Method::GET->isSafe());
        self::assertTrue(Method::HEAD->isSafe());
        self::assertTrue(Method::OPTIONS->isSafe());
        self::assertTrue(Method::TRACE->isSafe());

        self::assertFalse(Method::POST->isSafe());
        self::assertFalse(Method::PUT->isSafe());
        self::assertFalse(Method::DELETE->isSafe());
        self::assertFalse(Method::PATCH->isSafe());
    }

    #[Test]
    public function isIdempotentReturnsCorrectValues(): void
    {
        self::assertTrue(Method::GET->isIdempotent());
        self::assertTrue(Method::HEAD->isIdempotent());
        self::assertTrue(Method::PUT->isIdempotent());
        self::assertTrue(Method::DELETE->isIdempotent());
        self::assertTrue(Method::OPTIONS->isIdempotent());

        self::assertFalse(Method::POST->isIdempotent());
        self::assertFalse(Method::PATCH->isIdempotent());
    }

    #[Test]
    public function mayHaveBodyReturnsCorrectValues(): void
    {
        self::assertTrue(Method::POST->mayHaveBody());
        self::assertTrue(Method::PUT->mayHaveBody());
        self::assertTrue(Method::PATCH->mayHaveBody());

        self::assertFalse(Method::GET->mayHaveBody());
        self::assertFalse(Method::HEAD->mayHaveBody());
        self::assertFalse(Method::DELETE->mayHaveBody());
    }

    #[Test]
    public function fromStringIsCaseInsensitive(): void
    {
        self::assertSame(Method::GET, Method::fromString('get'));
        self::assertSame(Method::POST, Method::fromString('Post'));
        self::assertSame(Method::DELETE, Method::fromString('DELETE'));
    }
}
