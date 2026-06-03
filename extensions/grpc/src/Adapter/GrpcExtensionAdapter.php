<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Adapter;

use Grpc\Server as GrpcServer;
use Grpc\ServerCredentials;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Grpc\Exception\GrpcException;

use function extension_loaded;
use function is_array;
use function is_string;
use function strlen;

use const Grpc\OP_RECV_CLOSE_ON_SERVER;
use const Grpc\OP_SEND_INITIAL_METADATA;
use const Grpc\OP_SEND_MESSAGE;
use const Grpc\OP_SEND_STATUS_FROM_SERVER;

/**
 * Transport adapter for the grpc PECL extension (C-core).
 *
 * Uses \Grpc\Server from the grpc PHP extension to handle HTTP/2
 * transport and protobuf framing. Pulsar owns the service contracts
 * and interceptor pipeline; this adapter owns the wire protocol.
 */
#[Internal(reason: 'Transport adapter implementation; use GrpcTransportAdapterInterface')]
final class GrpcExtensionAdapter implements GrpcTransportAdapterInterface
{
    private bool $running = false;

    /**
     * @param string $certChain  PEM-encoded server certificate chain (for TLS)
     * @param string $privateKey PEM-encoded server private key (for TLS)
     * @param string $rootCert   PEM-encoded root CA certificate (for mTLS)
     */
    public function __construct(
        private readonly string $certChain = '',
        private readonly string $privateKey = '',
        private readonly string $rootCert = '',
    ) {}

    #[Override]
    public function listen(string $host, int $port, GrpcRequestHandler $handler): void
    {
        if (!$this->isAvailable()) {
            throw GrpcException::extensionNotLoaded('grpc');
        }

        $server = new GrpcServer([]);

        $address = $host . ':' . $port;

        if ($this->hasTlsCredentials()) {
            $rootCert = $this->rootCert !== '' ? $this->rootCert : null;
            $credentials = ServerCredentials::createSsl(
                $rootCert ?? '',
                [['cert_chain' => $this->certChain, 'private_key' => $this->privateKey]],
                $this->rootCert !== '',
            );
            $server->addHttp2Port($address, $credentials);
        } else {
            $server->addHttp2Port($address, ServerCredentials::createInsecure());
        }

        $server->start();
        $this->running = true;

        while ($this->running) {
            /** @var object|null $event */
            $event = $server->requestCall();

            if ($event === null) {
                continue;
            }

            $this->dispatchEvent($event, $handler);
        }
    }

    #[Override]
    public function shutdown(): void
    {
        // Setting `$this->running = false` causes the event loop in
        // listen() to exit on the next iteration. When listen() returns,
        // its local \Grpc\Server variable goes out of scope; the C-level
        // `grpc_server_destroy` runs from the extension's internal
        // destructor and drains in-flight RPCs + closes the HTTP/2
        // listener. PECL's \Grpc\Server does not expose an explicit
        // shutdown method.
        $this->running = false;
    }

    #[Override]
    public function name(): string
    {
        return 'grpc_extension';
    }

    #[Override]
    public function isAvailable(): bool
    {
        return extension_loaded('grpc');
    }

    private function hasTlsCredentials(): bool
    {
        return $this->certChain !== '' && $this->privateKey !== '';
    }

    /**
     * Dispatch a single gRPC call event to the request handler.
     */
    private function dispatchEvent(object $event, GrpcRequestHandler $handler): void
    {
        /** @var string $method */
        $method = $event->method ?? '';

        $payload = '';

        /** @var object|null $call */
        $call = $event->call ?? null;

        if ($call !== null && method_exists($call, 'read')) {
            /** @var object $readEvent */
            $readEvent = $call->read();
            if (isset($readEvent->data) && is_string($readEvent->data)) {
                $payload = $readEvent->data;
            }
        }

        $metadata = [];
        if (isset($event->metadata) && is_array($event->metadata)) {
            /** @var mixed $values */
            foreach ($event->metadata as $key => $values) {
                if (is_string($key) && is_array($values)) {
                    $metadata = [...$metadata, $key => array_values(array_filter($values, 'is_string'))];
                }
            }
        }

        // Peer identity comes from the TLS handshake via $call->getPeer(),
        // not from client-controlled headers. The grpc PECL extension does not
        // expose client certificates directly, so peer identity is null unless
        // the transport provides it through a verified mechanism.
        $peerIdentity = null;

        if ($call !== null && method_exists($call, 'getPeer')) {
            /** @var string|false $peer */
            $peer = $call->getPeer();

            if ($peer !== false && $peer !== '') {
                $peerIdentity = $peer;
            }
        }

        $result = $handler->handle($method, $payload, $metadata, $peerIdentity);

        if ($call !== null && method_exists($call, 'startBatch')) {
            $call->startBatch([
                OP_SEND_INITIAL_METADATA => $result->trailers,
                OP_SEND_MESSAGE => strlen($result->payload) > 0 ? $result->payload : null,
                OP_SEND_STATUS_FROM_SERVER => [
                    'code' => $result->status->value,
                    'details' => $result->message,
                    'metadata' => $result->trailers,
                ],
                OP_RECV_CLOSE_ON_SERVER => true,
            ]);
        }
    }

}
