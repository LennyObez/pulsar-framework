<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing\Fake;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Testing\Fake\FakeHttpRequest;
use Pulsar\Testing\Fake\FakeHttpResponse;
use Pulsar\Testing\Fake\HttpClientFake;

final class HttpClientFakeTest extends TestCase
{
    // ── stub + send ───────────────────────────────────────────────────

    #[Test]
    public function sendReturnsStubResponse(): void
    {
        $client = HttpClientFake::create()
            ->stub('https://api.example.com/users', FakeHttpResponse::json(['ok' => true]));

        $response = $client->send('GET', 'https://api.example.com/users');

        self::assertSame(200, $response->status);
        self::assertSame(['ok' => true], $response->decodeJson());
    }

    #[Test]
    public function sendReturnsDefaultResponseWhenNoStubMatches(): void
    {
        $client = HttpClientFake::create()
            ->stubDefault(FakeHttpResponse::failed(503, 'Service Unavailable'));

        $response = $client->send('GET', 'https://unknown.com');

        self::assertSame(503, $response->status);
    }

    #[Test]
    public function sendReturnsEmpty200WhenNoStubOrDefault(): void
    {
        $client = HttpClientFake::create();

        $response = $client->send('GET', 'https://example.com');

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
    }

    #[Test]
    public function sendMatchesPartialUrl(): void
    {
        $client = HttpClientFake::create()
            ->stub('/users', FakeHttpResponse::json(['count' => 5]));

        $response = $client->send('GET', 'https://api.example.com/users?page=1');

        self::assertSame(5, $response->decodeJson()['count']);
    }

    // ── assertSent ────────────────────────────────────────────────────

    #[Test]
    public function assertSentPassesWhenRequestWasMade(): void
    {
        $client = HttpClientFake::create();
        $client->send('GET', 'https://api.example.com/users');

        $client->assertSent('GET', 'https://api.example.com/users');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function assertSentFailsWhenRequestWasNotMade(): void
    {
        $client = HttpClientFake::create();

        $this->expectException(AssertionFailedError::class);
        $client->assertSent('GET', 'https://api.example.com/users');
    }

    #[Test]
    public function assertSentWithMethodOnly(): void
    {
        $client = HttpClientFake::create();
        $client->send('POST', 'https://api.example.com/create');

        $client->assertSent('POST');

        $this->addToAssertionCount(1);
    }

    // ── assertSentWith ────────────────────────────────────────────────

    #[Test]
    public function assertSentWithPassesWhenCallbackMatches(): void
    {
        $client = HttpClientFake::create();
        $client->send('POST', 'https://api.example.com/users', '{"name":"Alice"}');

        $client->assertSentWith(fn(FakeHttpRequest $r): bool => $r->hasJsonKey('name'));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function assertSentWithFailsWhenNoCallbackMatches(): void
    {
        $client = HttpClientFake::create();
        $client->send('GET', 'https://api.example.com/users');

        $this->expectException(AssertionFailedError::class);
        $client->assertSentWith(fn(FakeHttpRequest $r): bool => $r->method === 'DELETE');
    }

    // ── assertNotSent ─────────────────────────────────────────────────

    #[Test]
    public function assertNotSentPassesWhenNoMatchingRequest(): void
    {
        $client = HttpClientFake::create();
        $client->send('GET', 'https://api.example.com/users');

        $client->assertNotSent('DELETE', 'https://api.example.com/users');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function assertNotSentFailsWhenMatchingRequestExists(): void
    {
        $client = HttpClientFake::create();
        $client->send('GET', 'https://api.example.com/users');

        $this->expectException(AssertionFailedError::class);
        $client->assertNotSent('GET', 'https://api.example.com/users');
    }

    // ── assertNothingSent ─────────────────────────────────────────────

    #[Test]
    public function assertNothingSentPassesWhenNoRequests(): void
    {
        $client = HttpClientFake::create();

        $client->assertNothingSent();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function assertNothingSentFailsWhenRequestsExist(): void
    {
        $client = HttpClientFake::create();
        $client->send('GET', 'https://example.com');

        $this->expectException(AssertionFailedError::class);
        $client->assertNothingSent();
    }

    // ── assertSentCount ───────────────────────────────────────────────

    #[Test]
    public function assertSentCountMatchesExactCount(): void
    {
        $client = HttpClientFake::create();
        $client->send('GET', 'https://example.com/a');
        $client->send('POST', 'https://example.com/b');

        $client->assertSentCount(2);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function assertSentCountFailsOnMismatch(): void
    {
        $client = HttpClientFake::create();
        $client->send('GET', 'https://example.com');

        $this->expectException(AssertionFailedError::class);
        $client->assertSentCount(5);
    }

    // ── recorded / reset ──────────────────────────────────────────────

    #[Test]
    public function recordedReturnsAllRequests(): void
    {
        $client = HttpClientFake::create();
        $client->send('GET', 'https://a.com');
        $client->send('POST', 'https://b.com', '{"x":1}');

        $recorded = $client->recorded();

        self::assertCount(2, $recorded);
        self::assertSame('GET', $recorded[0]->method);
        self::assertSame('POST', $recorded[1]->method);
    }

    #[Test]
    public function resetClearsAllState(): void
    {
        $client = HttpClientFake::create()
            ->stub('https://a.com', FakeHttpResponse::json(['a' => 1]))
            ->stubDefault(FakeHttpResponse::failed());

        $client->send('GET', 'https://a.com');
        $client->reset();

        self::assertEmpty($client->recorded());
        // Stubs should also be cleared
        $response = $client->send('GET', 'https://a.com');
        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
    }

    // ── FakeHttpRequest ───────────────────────────────────────────────

    #[Test]
    public function fakeHttpRequestHasJsonKeyDetectsKeys(): void
    {
        $request = new FakeHttpRequest('POST', '/test', '{"name":"Alice","age":30}');

        self::assertTrue($request->hasJsonKey('name'));
        self::assertTrue($request->hasJsonKey('age'));
        self::assertFalse($request->hasJsonKey('email'));
    }

    #[Test]
    public function fakeHttpRequestJsonValueReturnsValue(): void
    {
        $request = new FakeHttpRequest('POST', '/test', '{"name":"Alice"}');

        self::assertSame('Alice', $request->jsonValue('name'));
        self::assertNull($request->jsonValue('missing'));
    }

    #[Test]
    public function fakeHttpRequestWithNonJsonBody(): void
    {
        $request = new FakeHttpRequest('POST', '/test', 'not json');

        self::assertFalse($request->hasJsonKey('anything'));
        self::assertNull($request->jsonValue('anything'));
    }

    // ── FakeHttpResponse ──────────────────────────────────────────────

    #[Test]
    public function fakeHttpResponseJsonCreatesJsonResponse(): void
    {
        $response = FakeHttpResponse::json(['users' => []], 200);

        self::assertSame(200, $response->status);
        self::assertSame('application/json', $response->headers['Content-Type']);
        self::assertSame([], $response->decodeJson()['users']);
    }

    #[Test]
    public function fakeHttpResponseFailedCreatesErrorResponse(): void
    {
        $response = FakeHttpResponse::failed(500, 'Internal Error');

        self::assertSame(500, $response->status);
        self::assertFalse($response->isSuccessful());
    }

    #[Test]
    public function fakeHttpResponseIsSuccessfulChecks2xx(): void
    {
        self::assertTrue(FakeHttpResponse::json([])->isSuccessful());
        self::assertTrue(new FakeHttpResponse(201, '')->isSuccessful());
        self::assertFalse(new FakeHttpResponse(301, '')->isSuccessful());
        self::assertFalse(FakeHttpResponse::failed(400)->isSuccessful());
    }
}
