<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Message;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\Stream;

#[CoversClass(Response::class)]
final class ResponseTest extends TestCase
{
    // ── Constructor ────────────────────────────────────────────────────

    #[Test]
    public function defaultConstructorValues(): void
    {
        $response = new Response();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
        self::assertSame('1.1', $response->getProtocolVersion());
        self::assertSame('', (string) $response->getBody());
        self::assertSame([], $response->getHeaders());
    }

    #[Test]
    public function constructorAcceptsAllParameters(): void
    {
        $body = Stream::create('response body');
        $response = new Response(
            statusCode: 404,
            reasonPhrase: 'Custom Not Found',
            headers: ['Content-Type' => 'text/html', 'X-Multi' => ['a', 'b']],
            body: $body,
            protocolVersion: '2.0',
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Custom Not Found', $response->getReasonPhrase());
        self::assertSame('2.0', $response->getProtocolVersion());
        self::assertSame($body, $response->getBody());
        self::assertSame('text/html', $response->getHeaderLine('Content-Type'));
        self::assertSame('a, b', $response->getHeaderLine('X-Multi'));
    }

    #[Test]
    public function constructorUsesDefaultReasonPhrase(): void
    {
        $response = new Response(statusCode: 201);
        self::assertSame('Created', $response->getReasonPhrase());
    }

    #[Test]
    public function constructorAcceptsStringBody(): void
    {
        $response = new Response(body: 'string body');
        self::assertSame('string body', (string) $response->getBody());
    }

    // ── Convenience Factories ─────────────────────────────────────────

    #[Test]
    public function jsonCreatesJsonResponse(): void
    {
        $response = Response::json(['key' => 'value']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"key":"value"}', (string) $response->getBody());
    }

    #[Test]
    public function jsonAcceptsCustomStatus(): void
    {
        $response = Response::json(['error' => 'not found'], status: 404);
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function htmlCreatesHtmlResponse(): void
    {
        $response = Response::html('<h1>Hello</h1>');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('<h1>Hello</h1>', (string) $response->getBody());
    }

    #[Test]
    public function htmlAcceptsCustomStatus(): void
    {
        $response = Response::html('<p>Error</p>', status: 500);
        self::assertSame(500, $response->getStatusCode());
    }

    #[Test]
    public function textCreatesTextResponse(): void
    {
        $response = Response::text('Hello, World!');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('Hello, World!', (string) $response->getBody());
    }

    #[Test]
    public function textAcceptsCustomStatus(): void
    {
        $response = Response::text('Error', status: 503);
        self::assertSame(503, $response->getStatusCode());
    }

    #[Test]
    public function redirectCreatesRedirectResponse(): void
    {
        $response = Response::redirect('/new-location');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/new-location', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function redirectAcceptsCustomStatus(): void
    {
        $response = Response::redirect('/permanent', status: 301);
        self::assertSame(301, $response->getStatusCode());
    }

    #[Test]
    public function redirectRejectsEmptyUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (void) Response::redirect('');
    }

    #[Test]
    public function noContentCreatesEmpty204Response(): void
    {
        $response = Response::noContent();

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    #[Test]
    public function validationErrorCreates422JsonResponse(): void
    {
        $violations = [
            ['field' => 'email', 'message' => 'Required.', 'rule' => 'required'],
        ];

        $response = Response::validationError($violations);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));

        /** @var array{error: string, status: int, violations: list<mixed>} $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Validation Failed', $data['error']);
        self::assertSame(422, $data['status']);
        self::assertCount(1, $data['violations']);
    }

    // ── PSR-7 MessageInterface ────────────────────────────────────────

    #[Test]
    public function withProtocolVersionReturnsNewInstance(): void
    {
        $original = new Response();
        $new = $original->withProtocolVersion('2.0');

        self::assertNotSame($original, $new);
        self::assertSame('1.1', $original->getProtocolVersion());
        self::assertSame('2.0', $new->getProtocolVersion());
    }

    #[Test]
    public function withProtocolVersionReturnsSameWhenUnchanged(): void
    {
        $response = new Response(protocolVersion: '1.1');
        self::assertSame($response, $response->withProtocolVersion('1.1'));
    }

    #[Test]
    public function getHeadersPreservesOriginalCase(): void
    {
        $response = new Response(headers: [
            'Content-Type' => 'text/html',
            'X-Custom' => ['val1', 'val2'],
        ]);

        $headers = $response->getHeaders();
        self::assertArrayHasKey('Content-Type', $headers);
        self::assertArrayHasKey('X-Custom', $headers);
        self::assertSame(['text/html'], $headers['Content-Type']);
        self::assertSame(['val1', 'val2'], $headers['X-Custom']);
    }

    #[Test]
    public function hasHeaderIsCaseInsensitive(): void
    {
        $response = new Response(headers: ['X-Token' => 'abc']);

        self::assertTrue($response->hasHeader('X-Token'));
        self::assertTrue($response->hasHeader('x-token'));
        self::assertFalse($response->hasHeader('Missing'));
    }

    #[Test]
    public function getHeaderIsCaseInsensitive(): void
    {
        $response = new Response(headers: ['Accept' => ['text/html', 'text/xml']]);

        self::assertSame(['text/html', 'text/xml'], $response->getHeader('accept'));
        self::assertSame([], $response->getHeader('Missing'));
    }

    #[Test]
    public function getHeaderLineJoinsValues(): void
    {
        $response = new Response(headers: ['Accept' => ['text/html', 'text/xml']]);

        self::assertSame('text/html, text/xml', $response->getHeaderLine('Accept'));
        self::assertSame('', $response->getHeaderLine('Missing'));
    }

    #[Test]
    public function withHeaderReplacesExisting(): void
    {
        $response = new Response(headers: ['Content-Type' => 'text/html']);
        $new = $response->withHeader('Content-Type', 'application/json');

        self::assertSame('text/html', $response->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $new->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function withHeaderAcceptsArrayValue(): void
    {
        $response = new Response();
        $new = $response->withHeader('Accept', ['text/html', 'text/xml']);

        self::assertSame(['text/html', 'text/xml'], $new->getHeader('Accept'));
    }

    #[Test]
    public function withAddedHeaderAppendsToExisting(): void
    {
        $response = new Response(headers: ['Accept' => 'text/html']);
        $new = $response->withAddedHeader('Accept', 'text/xml');

        self::assertSame(['text/html', 'text/xml'], $new->getHeader('Accept'));
    }

    #[Test]
    public function withAddedHeaderCreatesNewWhenMissing(): void
    {
        $response = new Response();
        $new = $response->withAddedHeader('X-New', 'value');

        self::assertSame(['value'], $new->getHeader('X-New'));
    }

    #[Test]
    public function withAddedHeaderAcceptsArrayValue(): void
    {
        $response = new Response();
        $new = $response->withAddedHeader('Accept', ['text/html', 'text/xml']);

        self::assertSame(['text/html', 'text/xml'], $new->getHeader('Accept'));
    }

    #[Test]
    public function withoutHeaderRemovesHeader(): void
    {
        $response = new Response(headers: ['X-Remove' => 'val', 'Keep' => 'yes']);
        $new = $response->withoutHeader('X-Remove');

        self::assertFalse($new->hasHeader('X-Remove'));
        self::assertTrue($new->hasHeader('Keep'));
    }

    #[Test]
    public function withoutHeaderReturnsSameWhenMissing(): void
    {
        $response = new Response();
        self::assertSame($response, $response->withoutHeader('Non-Existent'));
    }

    #[Test]
    public function withBodyReturnsNewInstance(): void
    {
        $original = new Response(body: 'original');
        $newBody = Stream::create('new');
        $new = $original->withBody($newBody);

        self::assertNotSame($original, $new);
        self::assertSame('original', (string) $original->getBody());
        self::assertSame('new', (string) $new->getBody());
    }

    // ── PSR-7 ResponseInterface ───────────────────────────────────────

    #[Test]
    public function withStatusReturnsNewInstance(): void
    {
        $original = new Response(statusCode: 200);
        $new = $original->withStatus(404);

        self::assertNotSame($original, $new);
        self::assertSame(200, $original->getStatusCode());
        self::assertSame(404, $new->getStatusCode());
        self::assertSame('Not Found', $new->getReasonPhrase());
    }

    #[Test]
    public function withStatusAcceptsCustomReason(): void
    {
        $response = new Response()->withStatus(200, 'All Good');
        self::assertSame('All Good', $response->getReasonPhrase());
    }

    #[Test]
    public function withStatusUsesDefaultReasonWhenEmpty(): void
    {
        $response = new Response()->withStatus(500);
        self::assertSame('Internal Server Error', $response->getReasonPhrase());
    }

    // ── Convenience Inspectors ────────────────────────────────────────

    #[Test]
    public function isEmptyReturnsTrueForEmptyBody(): void
    {
        self::assertTrue(new Response()->isEmpty());
        self::assertFalse(new Response(body: 'content')->isEmpty());
    }

    #[Test]
    public function contentLengthReturnsHeaderValue(): void
    {
        $response = new Response(headers: ['Content-Length' => '123']);
        self::assertSame(123, $response->contentLength());
    }

    #[Test]
    public function contentLengthReturnsNullWhenNotSet(): void
    {
        self::assertNull(new Response()->contentLength());
    }

    #[Test]
    public function contentTypeReturnsHeaderValue(): void
    {
        $response = new Response(headers: ['Content-Type' => 'text/html']);
        self::assertSame('text/html', $response->contentType());
    }

    #[Test]
    public function contentTypeReturnsNullWhenNotSet(): void
    {
        self::assertNull(new Response()->contentType());
    }

    #[Test]
    public function unknownStatusCodeReturnsEmptyReasonPhrase(): void
    {
        $response = new Response(statusCode: 999);
        self::assertSame('', $response->getReasonPhrase());
    }
}
