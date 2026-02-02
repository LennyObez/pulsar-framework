<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

#[CoversClass(Response::class)]
#[CoversClass(ResponseStatus::class)]
final class ResponseTest extends TestCase
{
    #[Test]
    public function defaultConstructorValues(): void
    {
        $response = new Response();

        self::assertSame('', $response->body);
        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertInstanceOf(HeaderBag::class, $response->headers);
        self::assertSame('1.1', $response->protocolVersion);
    }

    #[Test]
    public function constructorAcceptsAllParameters(): void
    {
        $headers = new HeaderBag(['X-Test' => 'value']);

        $response = new Response(
            body: 'Hello',
            status: ResponseStatus::Created,
            headers: $headers,
            protocolVersion: '2.0',
        );

        self::assertSame('Hello', $response->body);
        self::assertSame(ResponseStatus::Created, $response->status);
        self::assertSame($headers, $response->headers);
        self::assertSame('2.0', $response->protocolVersion);
    }

    #[Test]
    public function withBodyReturnsNewResponse(): void
    {
        $original = new Response(body: 'original');
        $new = $original->withBody('new');

        self::assertNotSame($original, $new);
        self::assertSame('original', $original->body);
        self::assertSame('new', $new->body);
    }

    #[Test]
    public function withStatusReturnsNewResponse(): void
    {
        $original = new Response(status: ResponseStatus::OK);
        $new = $original->withStatus(ResponseStatus::NotFound);

        self::assertNotSame($original, $new);
        self::assertSame(ResponseStatus::OK, $original->status);
        self::assertSame(ResponseStatus::NotFound, $new->status);
    }

    #[Test]
    public function withHeaderReturnsNewResponse(): void
    {
        $original = new Response();
        $new = $original->withHeader('X-Test', 'value');

        self::assertNotSame($original, $new);
        self::assertFalse($original->headers->has('X-Test'));
        self::assertTrue($new->headers->has('X-Test'));
    }

    #[Test]
    public function withAddedHeaderAppendsValue(): void
    {
        $response = new Response(headers: new HeaderBag(['Accept' => 'text/html']));
        $new = $response->withAddedHeader('Accept', 'application/json');

        self::assertSame(['text/html', 'application/json'], $new->headers->get('Accept'));
    }

    #[Test]
    public function withoutHeaderRemovesHeader(): void
    {
        $response = new Response(headers: new HeaderBag(['X-Remove' => 'value']));
        $new = $response->withoutHeader('X-Remove');

        self::assertFalse($new->headers->has('X-Remove'));
    }

    #[Test]
    public function withProtocolVersionReturnsNewResponse(): void
    {
        $original = new Response(protocolVersion: '1.1');
        $new = $original->withProtocolVersion('2.0');

        self::assertNotSame($original, $new);
        self::assertSame('1.1', $original->protocolVersion);
        self::assertSame('2.0', $new->protocolVersion);
    }

    #[Test]
    public function isEmptyReturnsTrueForEmptyBody(): void
    {
        $empty = new Response(body: '');
        $notEmpty = new Response(body: 'content');

        self::assertTrue($empty->isEmpty());
        self::assertFalse($notEmpty->isEmpty());
    }

    #[Test]
    public function contentLengthReturnsHeaderValue(): void
    {
        $response = new Response(headers: new HeaderBag(['Content-Length' => '123']));

        self::assertSame(123, $response->contentLength());
    }

    #[Test]
    public function contentLengthReturnsNullIfNotSet(): void
    {
        $response = new Response();

        self::assertNull($response->contentLength());
    }

    #[Test]
    public function contentTypeReturnsHeaderValue(): void
    {
        $response = new Response(headers: new HeaderBag(['Content-Type' => 'text/html']));

        self::assertSame('text/html', $response->contentType());
    }

    #[Test]
    public function jsonCreatesJsonResponse(): void
    {
        $response = Response::json(['key' => 'value']);

        self::assertSame('{"key":"value"}', $response->body);
        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers->first('Content-Type'));
    }

    #[Test]
    public function jsonAcceptsCustomStatus(): void
    {
        $response = Response::json(['error' => 'Not found'], ResponseStatus::NotFound);

        self::assertSame(ResponseStatus::NotFound, $response->status);
    }

    #[Test]
    public function htmlCreatesHtmlResponse(): void
    {
        $response = Response::html('<h1>Hello</h1>');

        self::assertSame('<h1>Hello</h1>', $response->body);
        self::assertSame('text/html; charset=utf-8', $response->headers->first('Content-Type'));
    }

    #[Test]
    public function textCreatesTextResponse(): void
    {
        $response = Response::text('Hello, World!');

        self::assertSame('Hello, World!', $response->body);
        self::assertSame('text/plain; charset=utf-8', $response->headers->first('Content-Type'));
    }

    #[Test]
    public function redirectCreatesRedirectResponse(): void
    {
        $response = Response::redirect('/new-location');

        self::assertSame('', $response->body);
        self::assertSame(ResponseStatus::Found, $response->status);
        self::assertSame('/new-location', $response->headers->first('Location'));
    }

    #[Test]
    public function redirectAcceptsCustomStatus(): void
    {
        $response = Response::redirect('/permanent', ResponseStatus::MovedPermanently);

        self::assertSame(ResponseStatus::MovedPermanently, $response->status);
    }

    #[Test]
    public function noContentCreatesEmptyResponse(): void
    {
        $response = Response::noContent();

        self::assertSame('', $response->body);
        self::assertSame(ResponseStatus::NoContent, $response->status);
    }
}

#[CoversClass(ResponseStatus::class)]
final class ResponseStatusEnumTest extends TestCase
{
    #[Test]
    public function reasonPhraseReturnsCorrectText(): void
    {
        self::assertSame('OK', ResponseStatus::OK->reasonPhrase());
        self::assertSame('Not Found', ResponseStatus::NotFound->reasonPhrase());
        self::assertSame('Internal Server Error', ResponseStatus::InternalServerError->reasonPhrase());
        self::assertSame("I'm a teapot", ResponseStatus::ImATeapot->reasonPhrase());
    }

    #[Test]
    public function isInformationalReturnsCorrectValues(): void
    {
        self::assertTrue(ResponseStatus::Continue->isInformational());
        self::assertTrue(ResponseStatus::SwitchingProtocols->isInformational());
        self::assertFalse(ResponseStatus::OK->isInformational());
    }

    #[Test]
    public function isSuccessfulReturnsCorrectValues(): void
    {
        self::assertTrue(ResponseStatus::OK->isSuccessful());
        self::assertTrue(ResponseStatus::Created->isSuccessful());
        self::assertTrue(ResponseStatus::NoContent->isSuccessful());
        self::assertFalse(ResponseStatus::NotFound->isSuccessful());
    }

    #[Test]
    public function isRedirectionReturnsCorrectValues(): void
    {
        self::assertTrue(ResponseStatus::MovedPermanently->isRedirection());
        self::assertTrue(ResponseStatus::Found->isRedirection());
        self::assertFalse(ResponseStatus::OK->isRedirection());
    }

    #[Test]
    public function isClientErrorReturnsCorrectValues(): void
    {
        self::assertTrue(ResponseStatus::BadRequest->isClientError());
        self::assertTrue(ResponseStatus::NotFound->isClientError());
        self::assertTrue(ResponseStatus::Forbidden->isClientError());
        self::assertFalse(ResponseStatus::InternalServerError->isClientError());
    }

    #[Test]
    public function isServerErrorReturnsCorrectValues(): void
    {
        self::assertTrue(ResponseStatus::InternalServerError->isServerError());
        self::assertTrue(ResponseStatus::BadGateway->isServerError());
        self::assertFalse(ResponseStatus::NotFound->isServerError());
    }

    #[Test]
    public function isErrorReturnsCorrectValues(): void
    {
        self::assertTrue(ResponseStatus::BadRequest->isError());
        self::assertTrue(ResponseStatus::NotFound->isError());
        self::assertTrue(ResponseStatus::InternalServerError->isError());
        self::assertFalse(ResponseStatus::OK->isError());
        self::assertFalse(ResponseStatus::Found->isError());
    }
}
