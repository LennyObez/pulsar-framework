<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Psr7Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psr7Bridge\Adapter\Psr7ToPulsarResponse;
use Pulsar\Extension\Psr7Bridge\Adapter\PulsarToPsr7Response;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

#[CoversClass(PulsarToPsr7Response::class)]
#[CoversClass(Psr7ToPulsarResponse::class)]
final class PulsarToPsr7ResponseTest extends TestCase
{
    #[Test]
    public function convertsStatusCode(): void
    {
        $response = new Response(
            body: '',
            status: ResponseStatus::NotFound,
        );

        $adapter = new PulsarToPsr7Response();
        $psrResponse = $adapter->convert($response);

        self::assertSame(404, $psrResponse->getStatusCode());
        self::assertSame('Not Found', $psrResponse->getReasonPhrase());
    }

    #[Test]
    public function convertsBody(): void
    {
        $body = '{"message":"hello"}';
        $response = new Response(
            body: $body,
            status: ResponseStatus::OK,
        );

        $adapter = new PulsarToPsr7Response();
        $psrResponse = $adapter->convert($response);

        self::assertSame($body, (string) $psrResponse->getBody());
    }

    #[Test]
    public function convertsHeaders(): void
    {
        $response = new Response(
            body: '',
            status: ResponseStatus::OK,
            headers: new HeaderBag([
                'Content-Type' => 'text/html; charset=utf-8',
                'X-Frame-Options' => 'DENY',
            ]),
        );

        $adapter = new PulsarToPsr7Response();
        $psrResponse = $adapter->convert($response);

        self::assertSame(['text/html; charset=utf-8'], $psrResponse->getHeader('Content-Type'));
        self::assertSame(['DENY'], $psrResponse->getHeader('X-Frame-Options'));
    }

    #[Test]
    public function convertsProtocolVersion(): void
    {
        $response = new Response(
            body: '',
            status: ResponseStatus::OK,
            protocolVersion: '2.0',
        );

        $adapter = new PulsarToPsr7Response();
        $psrResponse = $adapter->convert($response);

        self::assertSame('2.0', $psrResponse->getProtocolVersion());
    }

    #[Test]
    public function roundtripPreservesAllFields(): void
    {
        $original = new Response(
            body: '<h1>Hello</h1>',
            status: ResponseStatus::Created,
            headers: new HeaderBag([
                'Content-Type' => 'text/html',
                'X-Request-Id' => 'req-abc',
                'Set-Cookie' => ['a=1', 'b=2'],
            ]),
            protocolVersion: '1.1',
        );

        $toPsr7 = new PulsarToPsr7Response();
        $toPulsar = new Psr7ToPulsarResponse();

        $psrResponse = $toPsr7->convert($original);
        $roundtripped = $toPulsar->convert($psrResponse);

        self::assertSame($original->body, $roundtripped->body);
        self::assertSame($original->status, $roundtripped->status);
        self::assertSame($original->protocolVersion, $roundtripped->protocolVersion);

        self::assertSame(
            $original->headers->first('Content-Type'),
            $roundtripped->headers->first('Content-Type'),
        );
        self::assertSame(
            $original->headers->first('X-Request-Id'),
            $roundtripped->headers->first('X-Request-Id'),
        );
        self::assertSame(
            $original->headers->get('Set-Cookie'),
            $roundtripped->headers->get('Set-Cookie'),
        );
    }

    #[Test]
    public function convertsMultipleHeaderValues(): void
    {
        $response = new Response(
            body: '',
            status: ResponseStatus::OK,
            headers: new HeaderBag([
                'Set-Cookie' => ['session=abc; Path=/', 'theme=dark; Path=/'],
            ]),
        );

        $adapter = new PulsarToPsr7Response();
        $psrResponse = $adapter->convert($response);

        self::assertSame(
            ['session=abc; Path=/', 'theme=dark; Path=/'],
            $psrResponse->getHeader('Set-Cookie'),
        );
    }

    #[Test]
    public function convertsEmptyResponse(): void
    {
        $response = new Response(
            body: '',
            status: ResponseStatus::NoContent,
        );

        $adapter = new PulsarToPsr7Response();
        $psrResponse = $adapter->convert($response);

        self::assertSame(204, $psrResponse->getStatusCode());
        self::assertSame('', (string) $psrResponse->getBody());
    }

    #[Test]
    public function psr7ToPulsarConvertsStatusCode(): void
    {
        $adapter = new Psr7ToPulsarResponse();

        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $psrResponse = $factory->createResponse(422, 'Unprocessable Entity');
        $psrResponse = $psrResponse->withBody($factory->createStream('{"error":"invalid"}'));
        $psrResponse = $psrResponse->withHeader('Content-Type', 'application/json');

        $pulsarResponse = $adapter->convert($psrResponse);

        self::assertSame(ResponseStatus::UnprocessableEntity, $pulsarResponse->status);
        self::assertSame('{"error":"invalid"}', $pulsarResponse->body);
        self::assertSame('application/json', $pulsarResponse->headers->first('Content-Type'));
    }
}
