<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Adapter\GrpcExtensionAdapter;
use Pulsar\Extension\Grpc\Adapter\GrpcRequestHandler;
use RuntimeException;

use function extension_loaded;

#[CoversClass(GrpcExtensionAdapter::class)]
final class GrpcExtensionAdapterTest extends TestCase
{
    #[Test]
    public function name_returns_grpc_extension(): void
    {
        $adapter = new GrpcExtensionAdapter();

        self::assertSame('grpc_extension', $adapter->name());
    }

    #[Test]
    public function is_available_reflects_extension_loaded_status(): void
    {
        $adapter = new GrpcExtensionAdapter();

        // isAvailable() delegates to extension_loaded('grpc')
        self::assertSame(extension_loaded('grpc'), $adapter->isAvailable());
    }

    #[Test]
    public function listen_throws_when_extension_not_loaded(): void
    {
        if (extension_loaded('grpc')) {
            self::markTestSkipped('The grpc extension is loaded; cannot test unavailability.');
        }

        $adapter = new GrpcExtensionAdapter();
        $handler = $this->createStub(GrpcRequestHandler::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('grpc PHP extension is not loaded');

        $adapter->listen('0.0.0.0', 50051, $handler);
    }

    #[Test]
    public function shutdown_is_safe_when_not_started(): void
    {
        $adapter = new GrpcExtensionAdapter();

        // Shutdown before start should not throw.
        $adapter->shutdown();

        self::assertSame('grpc_extension', $adapter->name());
    }

    #[Test]
    public function constructs_with_tls_credentials(): void
    {
        $adapter = new GrpcExtensionAdapter(
            certChain: 'cert-data',
            privateKey: 'key-data',
            rootCert: 'ca-data',
        );

        self::assertSame('grpc_extension', $adapter->name());
    }

    #[Test]
    public function constructs_with_empty_credentials(): void
    {
        $adapter = new GrpcExtensionAdapter(
            certChain: '',
            privateKey: '',
            rootCert: '',
        );

        self::assertSame('grpc_extension', $adapter->name());
    }
}
