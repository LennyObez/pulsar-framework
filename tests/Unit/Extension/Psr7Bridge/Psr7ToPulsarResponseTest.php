<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Psr7Bridge;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psr7Bridge\Adapter\Psr7ToPulsarResponse;
use Pulsar\Http\ResponseStatus;

#[CoversClass(Psr7ToPulsarResponse::class)]
final class Psr7ToPulsarResponseTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    #[Test]
    public function convertsStatusCode(): void
    {
        $psrResponse = $this->factory->createResponse(201, 'Created');

        $adapter = new Psr7ToPulsarResponse();
        $pulsarResponse = $adapter->convert($psrResponse);

        self::assertSame(ResponseStatus::Created, $pulsarResponse->status);
    }

    #[Test]
    public function convertsBody(): void
    {
        $psrResponse = $this->factory->createResponse(200)
            ->withBody($this->factory->createStream('Hello World'));

        $adapter = new Psr7ToPulsarResponse();
        $pulsarResponse = $adapter->convert($psrResponse);

        self::assertSame('Hello World', $pulsarResponse->body);
    }

    #[Test]
    public function convertsHeaders(): void
    {
        $psrResponse = $this->factory->createResponse(200)
            ->withHeader('Content-Type', 'text/plain')
            ->withHeader('X-Custom', 'test-value');

        $adapter = new Psr7ToPulsarResponse();
        $pulsarResponse = $adapter->convert($psrResponse);

        self::assertSame('text/plain', $pulsarResponse->headers->first('Content-Type'));
        self::assertSame('test-value', $pulsarResponse->headers->first('X-Custom'));
    }

    #[Test]
    public function convertsProtocolVersion(): void
    {
        $psrResponse = $this->factory->createResponse(200)
            ->withProtocolVersion('2.0');

        $adapter = new Psr7ToPulsarResponse();
        $pulsarResponse = $adapter->convert($psrResponse);

        self::assertSame('2.0', $pulsarResponse->protocolVersion);
    }

    #[Test]
    public function convertsEmptyBody(): void
    {
        $psrResponse = $this->factory->createResponse(204);

        $adapter = new Psr7ToPulsarResponse();
        $pulsarResponse = $adapter->convert($psrResponse);

        self::assertSame(ResponseStatus::NoContent, $pulsarResponse->status);
        self::assertSame('', $pulsarResponse->body);
    }
}
