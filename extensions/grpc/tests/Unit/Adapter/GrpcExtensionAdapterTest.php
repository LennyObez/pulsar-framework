<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Adapter\GrpcExtensionAdapter;
use Pulsar\Extension\Grpc\Adapter\GrpcRequestHandler;
use Pulsar\Extension\Grpc\Interceptor\InterceptorResult;
use ReflectionMethod;
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

    #[Test]
    public function dispatch_never_passes_the_transport_peer_address_as_identity(): void
    {
        // C9: PECL's $call->getPeer() returns the transport endpoint, not a
        // verified client-cert SAN. Even when the call exposes one, the adapter
        // must pass null so the auth pipeline requires a bearer token.
        $handler = new class implements GrpcRequestHandler {
            public ?string $captured = 'sentinel';

            public function handle(
                string $fullMethodName,
                string $payload,
                array $metadata = [],
                ?string $peerIdentity = null,
            ): InterceptorResult {
                $this->captured = $peerIdentity;

                return InterceptorResult::ok('');
            }
        };

        // A fake grpc call that DOES expose a transport peer address, but omits
        // startBatch() (which references Grpc\OP_* constants only defined when
        // the extension is loaded), so dispatchEvent can run without the ext.
        $call = new class {
            public function getPeer(): string
            {
                return 'ipv4:203.0.113.7:54321';
            }
        };

        $event = new class ($call) {
            public function __construct(
                public object $call,
                public string $method = '/pkg.Service/Method',
            ) {}
        };

        $adapter = new GrpcExtensionAdapter();
        new ReflectionMethod($adapter, 'dispatchEvent')->invoke($adapter, $event, $handler);

        self::assertNull(
            $handler->captured,
            'the transport peer address must not be passed as a verified identity',
        );
    }
}
