<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psr7Bridge\Tests\Unit\Adapter;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as PsrResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psr7Bridge\Adapter\Psr7ToPulsarResponse;
use Pulsar\Http\ResponseStatus;

final class Psr7ToPulsarResponseTest extends TestCase
{
    private Psr7ToPulsarResponse $adapter;

    protected function setUp(): void
    {
        $this->adapter = new Psr7ToPulsarResponse();
    }

    #[Test]
    public function convertPreservesStatusCode(): void
    {
        $psr = new PsrResponse(201);
        $response = $this->adapter->convert($psr);

        self::assertSame(ResponseStatus::Created, $response->status);
    }

    #[Test]
    public function convertPreservesHeaders(): void
    {
        $psr = new PsrResponse()
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Custom', 'value');
        $response = $this->adapter->convert($psr);

        self::assertTrue($response->headers->has('Content-Type'));
        self::assertSame('application/json', $response->headers->first('Content-Type'));
        self::assertSame('value', $response->headers->first('X-Custom'));
    }

    #[Test]
    public function convertPreservesBody(): void
    {
        $factory = new Psr17Factory();
        $psr = new PsrResponse()->withBody($factory->createStream('Hello World'));
        $response = $this->adapter->convert($psr);

        self::assertSame('Hello World', $response->body);
    }

    #[Test]
    public function convertPreservesProtocolVersion(): void
    {
        $psr = new PsrResponse()->withProtocolVersion('2.0');
        $response = $this->adapter->convert($psr);

        self::assertSame('2.0', $response->protocolVersion);
    }
}
