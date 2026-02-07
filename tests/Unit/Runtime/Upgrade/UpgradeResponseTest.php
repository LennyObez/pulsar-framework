<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Upgrade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\ResponseStatus;
use Pulsar\Runtime\Upgrade\UpgradeHandlerInterface;
use Pulsar\Runtime\Upgrade\UpgradeResponse;

#[CoversClass(UpgradeResponse::class)]
final class UpgradeResponseTest extends TestCase
{
    #[Test]
    public function it_creates_with_switching_protocols_status(): void
    {
        $handler = $this->createStub(UpgradeHandlerInterface::class);
        $response = new UpgradeResponse($handler);

        self::assertSame(ResponseStatus::SwitchingProtocols, $response->status);
    }

    #[Test]
    public function it_exposes_the_upgrade_handler(): void
    {
        $handler = $this->createStub(UpgradeHandlerInterface::class);
        $response = new UpgradeResponse($handler);

        self::assertSame($handler, $response->handler);
    }

    #[Test]
    public function it_has_empty_body(): void
    {
        $handler = $this->createStub(UpgradeHandlerInterface::class);
        $response = new UpgradeResponse($handler);

        self::assertSame('', $response->body);
    }

    #[Test]
    public function it_accepts_custom_headers(): void
    {
        $handler = $this->createStub(UpgradeHandlerInterface::class);
        $headers = new HeaderBag([
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Accept' => 'test-accept-key',
        ]);

        $response = new UpgradeResponse($handler, $headers);

        self::assertSame('websocket', $response->headers->first('Upgrade'));
        self::assertSame('Upgrade', $response->headers->first('Connection'));
        self::assertSame('test-accept-key', $response->headers->first('Sec-WebSocket-Accept'));
    }

    #[Test]
    public function it_defaults_to_empty_headers(): void
    {
        $handler = $this->createStub(UpgradeHandlerInterface::class);
        $response = new UpgradeResponse($handler);

        self::assertTrue($response->headers->isEmpty());
    }
}
