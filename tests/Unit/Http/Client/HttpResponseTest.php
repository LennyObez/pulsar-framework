<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Client\HttpClientException;
use Pulsar\Http\Client\HttpResponse;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\ResponseStatus;
use RuntimeException;

#[CoversClass(HttpResponse::class)]
final class HttpResponseTest extends TestCase
{
    #[Test]
    public function statusReturnsIntegerCode(): void
    {
        $response = new HttpResponse(ResponseStatus::OK, new HeaderBag(), '');

        self::assertSame(200, $response->status());
    }

    #[Test]
    public function statusEnumReturnsEnum(): void
    {
        $response = new HttpResponse(ResponseStatus::NotFound, new HeaderBag(), '');

        self::assertSame(ResponseStatus::NotFound, $response->statusEnum());
    }

    #[Test]
    public function bodyReturnsRawBody(): void
    {
        $response = new HttpResponse(ResponseStatus::OK, new HeaderBag(), 'Hello World');

        self::assertSame('Hello World', $response->body());
    }

    #[Test]
    public function jsonDecodesResponseBody(): void
    {
        $data = ['name' => 'Pulsar', 'version' => '1.0.0'];
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $response = new HttpResponse(ResponseStatus::OK, new HeaderBag(), $body);

        self::assertSame($data, $response->json());
    }

    #[Test]
    public function jsonThrowsOnInvalidJson(): void
    {
        $response = new HttpResponse(ResponseStatus::OK, new HeaderBag(), 'not json');

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/Failed to parse response body as JSON/');

        $response->json();
    }

    #[Test]
    public function headersReturnsHeaderBag(): void
    {
        $headers = new HeaderBag(['Content-Type' => 'text/plain']);
        $response = new HttpResponse(ResponseStatus::OK, $headers, '');

        self::assertSame('text/plain', $response->headers()->first('Content-Type'));
    }

    #[Test]
    public function headerReturnsFirstValueForName(): void
    {
        $headers = new HeaderBag(['X-Request-Id' => 'abc123']);
        $response = new HttpResponse(ResponseStatus::OK, $headers, '');

        self::assertSame('abc123', $response->header('X-Request-Id'));
        self::assertNull($response->header('X-Missing'));
    }

    #[Test]
    public function okReturnsTrueFor2xxStatus(): void
    {
        self::assertTrue(new HttpResponse(ResponseStatus::OK, new HeaderBag(), '')->ok());
        self::assertTrue(new HttpResponse(ResponseStatus::Created, new HeaderBag(), '')->ok());
        self::assertFalse(new HttpResponse(ResponseStatus::NotFound, new HeaderBag(), '')->ok());
        self::assertFalse(new HttpResponse(ResponseStatus::InternalServerError, new HeaderBag(), '')->ok());
    }

    #[Test]
    public function redirectReturnsTrueFor3xxStatus(): void
    {
        self::assertTrue(new HttpResponse(ResponseStatus::Found, new HeaderBag(), '')->redirect());
        self::assertTrue(new HttpResponse(ResponseStatus::MovedPermanently, new HeaderBag(), '')->redirect());
        self::assertFalse(new HttpResponse(ResponseStatus::OK, new HeaderBag(), '')->redirect());
    }

    #[Test]
    public function clientErrorReturnsTrueFor4xxStatus(): void
    {
        self::assertTrue(new HttpResponse(ResponseStatus::NotFound, new HeaderBag(), '')->clientError());
        self::assertTrue(new HttpResponse(ResponseStatus::Forbidden, new HeaderBag(), '')->clientError());
        self::assertFalse(new HttpResponse(ResponseStatus::OK, new HeaderBag(), '')->clientError());
        self::assertFalse(new HttpResponse(ResponseStatus::InternalServerError, new HeaderBag(), '')->clientError());
    }

    #[Test]
    public function serverErrorReturnsTrueFor5xxStatus(): void
    {
        self::assertTrue(new HttpResponse(ResponseStatus::InternalServerError, new HeaderBag(), '')->serverError());
        self::assertTrue(new HttpResponse(ResponseStatus::BadGateway, new HeaderBag(), '')->serverError());
        self::assertFalse(new HttpResponse(ResponseStatus::NotFound, new HeaderBag(), '')->serverError());
    }

    #[Test]
    public function failedReturnsTrueFor4xxAnd5xxStatus(): void
    {
        self::assertTrue(new HttpResponse(ResponseStatus::NotFound, new HeaderBag(), '')->failed());
        self::assertTrue(new HttpResponse(ResponseStatus::InternalServerError, new HeaderBag(), '')->failed());
        self::assertFalse(new HttpResponse(ResponseStatus::OK, new HeaderBag(), '')->failed());
        self::assertFalse(new HttpResponse(ResponseStatus::Found, new HeaderBag(), '')->failed());
    }

    #[Test]
    public function throwDoesNothingForSuccessfulResponse(): void
    {
        $response = new HttpResponse(ResponseStatus::OK, new HeaderBag(), 'success');

        $result = $response->throw();
        self::assertSame($response, $result);
    }

    #[Test]
    public function throwThrowsForFailedResponse(): void
    {
        $response = new HttpResponse(ResponseStatus::InternalServerError, new HeaderBag(), 'server error');

        $this->expectException(HttpClientException::class);
        $this->expectExceptionCode(500);

        $response->throw();
    }

    #[Test]
    public function throwThrowsFor4xxResponse(): void
    {
        $response = new HttpResponse(ResponseStatus::Forbidden, new HeaderBag(), 'forbidden');

        $this->expectException(HttpClientException::class);
        $this->expectExceptionCode(403);

        $response->throw();
    }

    #[Test]
    public function isStatusChecksSpecificCode(): void
    {
        $response = new HttpResponse(ResponseStatus::Created, new HeaderBag(), '');

        self::assertTrue($response->isStatus(201));
        self::assertFalse($response->isStatus(200));
    }

    #[Test]
    public function contentTypeReturnsHeaderValue(): void
    {
        $headers = new HeaderBag(['Content-Type' => 'application/json; charset=utf-8']);
        $response = new HttpResponse(ResponseStatus::OK, $headers, '');

        self::assertSame('application/json; charset=utf-8', $response->contentType());
    }

    #[Test]
    public function contentTypeReturnsNullWhenMissing(): void
    {
        $response = new HttpResponse(ResponseStatus::OK, new HeaderBag(), '');

        self::assertNull($response->contentType());
    }

    #[Test]
    public function isJsonDetectsJsonContentType(): void
    {
        $jsonResponse = new HttpResponse(
            ResponseStatus::OK,
            new HeaderBag(['Content-Type' => 'application/json']),
            '{}',
        );
        self::assertTrue($jsonResponse->isJson());

        $jsonPlusResponse = new HttpResponse(
            ResponseStatus::OK,
            new HeaderBag(['Content-Type' => 'application/vnd.api+json']),
            '{}',
        );
        self::assertTrue($jsonPlusResponse->isJson());

        $htmlResponse = new HttpResponse(
            ResponseStatus::OK,
            new HeaderBag(['Content-Type' => 'text/html']),
            '<h1>Hello</h1>',
        );
        self::assertFalse($htmlResponse->isJson());
    }

    #[Test]
    public function isJsonReturnsFalseWhenNoContentType(): void
    {
        $response = new HttpResponse(ResponseStatus::OK, new HeaderBag(), '{}');

        self::assertFalse($response->isJson());
    }

    #[Test]
    public function fromRawConstructsFromPrimitives(): void
    {
        $response = HttpResponse::fromRaw(
            201,
            ['Content-Type' => 'application/json', 'X-Request-Id' => 'abc'],
            '{"id": 1}',
        );

        self::assertSame(201, $response->status());
        self::assertSame(ResponseStatus::Created, $response->statusEnum());
        self::assertSame('{"id": 1}', $response->body());
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame('abc', $response->header('X-Request-Id'));
    }

    #[Test]
    public function fromRawThrowsForUnknownStatusCode(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown HTTP status code: 999/');

        (void) HttpResponse::fromRaw(999, [], '');
    }
}
