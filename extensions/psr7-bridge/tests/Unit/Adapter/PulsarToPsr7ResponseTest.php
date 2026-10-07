<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psr7Bridge\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psr7Bridge\Adapter\PulsarToPsr7Response;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

final class PulsarToPsr7ResponseTest extends TestCase
{
    private PulsarToPsr7Response $adapter;

    protected function setUp(): void
    {
        $this->adapter = new PulsarToPsr7Response();
    }

    #[Test]
    public function convertPreservesStatusCode(): void
    {
        $response = new Response(status: ResponseStatus::NotFound);
        $psr = $this->adapter->convert($response);

        self::assertSame(404, $psr->getStatusCode());
    }

    #[Test]
    public function convertPreservesReasonPhrase(): void
    {
        $response = new Response(status: ResponseStatus::NotFound);
        $psr = $this->adapter->convert($response);

        self::assertSame('Not Found', $psr->getReasonPhrase());
    }

    #[Test]
    public function convertPreservesHeaders(): void
    {
        $response = new Response(
            headers: new HeaderBag(['Content-Type' => 'text/plain', 'X-Custom' => 'abc']),
        );
        $psr = $this->adapter->convert($response);

        self::assertSame('text/plain', $psr->getHeaderLine('Content-Type'));
        self::assertSame('abc', $psr->getHeaderLine('X-Custom'));
    }

    #[Test]
    public function convertPreservesBody(): void
    {
        $response = new Response(body: 'Hello World');
        $psr = $this->adapter->convert($response);

        self::assertSame('Hello World', (string) $psr->getBody());
    }

    #[Test]
    public function convertPreservesProtocolVersion(): void
    {
        $response = new Response(protocolVersion: '2.0');
        $psr = $this->adapter->convert($response);

        self::assertSame('2.0', $psr->getProtocolVersion());
    }
}
