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
     * @param string $rootCert   Client-CA bundle. REJECTED, see below: the grpc PHP
     *                           extension cannot request a client certificate, so
     *                           this can never produce mutual TLS
     */
    public function __construct(
        private readonly string $certChain = '',
        private readonly string $privateKey = '',
        string $rootCert = '',
    ) {
        // The docblock used to describe $rootCert as "for mTLS". It cannot be.
        // ext-grpc's ServerCredentials::createSsl() calls
        // grpc_ssl_server_credentials_create_ex() with a hardcoded
        // GRPC_SSL_DONT_REQUEST_CLIENT_CERTIFICATE, unconditionally and regardless
        // of the root certificates handed to it, so the server never asks a client
        // to identify itself and the CA bundle is never consulted. Accepting the
        // configuration would serve unauthenticated callers while the operator
        // believed mutual TLS was in force — a silent downgrade of exactly the
        // control they configured. Refused at construction, which is boot time, so
        // it cannot be discovered in production.
        if ($rootCert !== '') {
            throw GrpcException::invalidConfiguration(
                'grpc.tls.ca_path is set, which asks this transport for mutual TLS, but the grpc PHP '
                . 'extension cannot request or verify a client certificate: ServerCredentials::createSsl() '
                . 'always passes GRPC_SSL_DONT_REQUEST_CLIENT_CERTIFICATE, whatever CA bundle it is given. '
                . "Set grpc.adapter to 'roadrunner' to keep mutual TLS — that adapter terminates it in the "
                . 'proxy and feeds the verified identity through MtlsIdentityMapper — or clear '
                . 'grpc.tls.ca_path to serve one-way TLS knowingly.',
            );
        }
    }

    #[Override]
    public function listen(string $host, int $port, GrpcRequestHandler $handler): void
    {
        if (!$this->isAvailable()) {
            throw GrpcException::extensionNotLoaded('grpc');
        }

        $server = new GrpcServer([]);

        $address = $host . ':' . $port;

        if ($this->hasTlsCredentials()) {
            // ext-grpc parses createSsl() as "s!ss": a nullable client-CA bundle,
            // then the private key, then the certificate chain — in that order. The
            // previous call passed a grpc-core style array of key/cert pairs plus a
            // client-auth boolean, a signature the PHP extension has never had, so
            // under strict_types this raised a TypeError before anything bound. The
            // CA argument is null because the constructor refuses one outright.
            $credentials = ServerCredentials::createSsl(null, $this->privateKey, $this->certChain);

            // Credentials belong to addSecureHttp2Port(); addHttp2Port() takes an
            // address and nothing else. The old call passed credentials to the plain
            // variant, which would have bound a cleartext port had it been reached.
            $boundPort = $server->addSecureHttp2Port($address, $credentials);
        } else {
            // ServerCredentials::createInsecure() does not exist — the extension
            // declares no such factory. An insecure listener simply omits them.
            $boundPort = $server->addHttp2Port($address);
        }

        // Both calls answer with the bound port, or 0 when the bind failed. The
        // result was discarded, so a port already in use or an unusable certificate
        // produced a server that started and then answered nothing at all.
        if ($boundPort === 0) {
            throw GrpcException::serverStartFailed(
                'Could not bind the gRPC server to ' . $address
                . ' (port already in use, or the TLS material was rejected).',
            );
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

        // mTLS peer identity is intentionally null for this transport (C9).
        //
        // The grpc PECL extension's $call->getPeer() returns the TRANSPORT
        // endpoint (e.g. "ipv4:203.0.113.7:54321"), NOT the verified client
        // certificate SAN, and it is populated for every call regardless of
        // whether mTLS was negotiated. Passing it as $peerIdentity let any
        // tokenless request authenticate as its own source address, defeating
        // the AuthInterceptor's UNAUTHENTICATED gate. PECL exposes no client
        // certificate to extract a verified identity from, so there is nothing
        // trustworthy to supply here: pass null so the auth pipeline requires a
        // bearer token.
        $result = $handler->handle($method, $payload, $metadata, null);

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
