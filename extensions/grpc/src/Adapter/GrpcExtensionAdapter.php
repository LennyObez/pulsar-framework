<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Adapter;

use Override;
use Pulsar\Api\Internal;
use RuntimeException;

use function extension_loaded;
use function is_array;
use function is_string;
use function strlen;

/**
 * Transport adapter for the grpc PECL extension (C-core).
 *
 * Uses \Grpc\Server from the grpc PHP extension to handle HTTP/2
 * transport and protobuf framing. Pulsar owns the service contracts
 * and interceptor pipeline; this adapter owns the wire protocol.
 */
#[Internal(reason: 'Transport adapter implementation — use GrpcTransportAdapterInterface')]
final class GrpcExtensionAdapter implements GrpcTransportAdapterInterface
{
    private bool $running = false;

    /** @var \Grpc\Server|null */
    private ?object $server = null;

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
            throw new RuntimeException(
                'The grpc PHP extension is not loaded. Install it via: pecl install grpc',
            );
        }

        /** @var \Grpc\Server $server */
        $server = new \Grpc\Server();
        $this->server = $server;

        $address = $host . ':' . $port;

        if ($this->hasTlsCredentials()) {
            /** @phpstan-ignore class.notFound */
            $credentials = \Grpc\ServerCredentials::createSsl(
                $this->rootCert !== '' ? $this->rootCert : null,
                [['cert_chain' => $this->certChain, 'private_key' => $this->privateKey]],
                $this->rootCert !== '',
            );
            $server->addHttp2Port($address, $credentials);
        } else {
            /** @phpstan-ignore class.notFound */
            $server->addHttp2Port($address, \Grpc\ServerCredentials::createInsecure());
        }

        $server->start();
        $this->running = true;

        while ($this->running) {
            /** @var object|null $event */
            $event = $server->requestCall();

            if ($event === null) {
                continue;
            }

            $this->dispatchEvent($event, $handler, $server);
        }
    }

    #[Override]
    public function shutdown(): void
    {
        $this->running = false;

        if ($this->server !== null) {
            /** @phpstan-ignore method.notFound */
            $this->server->shutdown();
            $this->server = null;
        }
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
    private function dispatchEvent(object $event, GrpcRequestHandler $handler, object $server): void
    {
        /** @var string $method */
        $method = $event->method ?? '';

        /** @var string $payload */
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

        /** @var array<string, list<string>> $metadata */
        $metadata = [];
        if (isset($event->metadata) && is_array($event->metadata)) {
            foreach ($event->metadata as $key => $values) {
                if (is_string($key) && is_array($values)) {
                    $metadata[$key] = array_values(array_filter($values, 'is_string'));
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
                /** @phpstan-ignore classConstant.notFound */
                \Grpc\OP_SEND_INITIAL_METADATA => $result->trailers,
                \Grpc\OP_SEND_MESSAGE => strlen($result->payload) > 0 ? $result->payload : null,
                \Grpc\OP_SEND_STATUS_FROM_SERVER => [
                    'code' => $result->status->value,
                    'details' => $result->message,
                    'metadata' => $result->trailers,
                ],
                \Grpc\OP_RECV_CLOSE_ON_SERVER => true,
            ]);
        }
    }

}
