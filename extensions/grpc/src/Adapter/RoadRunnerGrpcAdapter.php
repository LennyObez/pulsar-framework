<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Adapter;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Grpc\Interceptor\InterceptorResult;
use RuntimeException;
use Spiral\RoadRunner\Payload;
use Spiral\RoadRunner\Worker;

use function call_user_func;
use function class_exists;
use function is_array;
use function is_string;

/**
 * Transport adapter for RoadRunner's gRPC plugin.
 *
 * Delegates wire-protocol handling to RoadRunner. Pulsar receives
 * deserialized gRPC frames via the RoadRunner worker protocol and
 * runs them through the Pulsar interceptor pipeline and service dispatch.
 */
#[Internal(reason: 'Transport adapter implementation; use GrpcTransportAdapterInterface')]
final class RoadRunnerGrpcAdapter implements GrpcTransportAdapterInterface
{
    private bool $running = false;

    /** @var object|null RoadRunner worker instance */
    private ?object $worker = null;

    /**
     * @param bool $trustedProxy When true, peer_identity from RoadRunner headers is trusted
     *                           (assumes RoadRunner extracts it from the TLS handshake).
     *                           When false, peer_identity from headers is ignored.
     */
    public function __construct(
        private readonly bool $trustedProxy = false,
    ) {}

    #[Override]
    public function listen(string $host, int $port, GrpcRequestHandler $handler): void
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException(
                'RoadRunner gRPC worker is not available. Ensure the application is running '
                . 'inside a RoadRunner process with the grpc plugin enabled.',
            );
        }

        // RoadRunner classes are only available at runtime inside the RR process.
        // isAvailable() guards this path, so Worker::create() is safe here.
        /** @var object $rrWorker */
        $rrWorker = call_user_func([Worker::class, 'create']);
        $this->worker = $rrWorker;
        $this->running = true;

        while ($this->running) {
            /** @var object|null $payload */
            $payload = method_exists($rrWorker, 'waitPayload') ? $rrWorker->waitPayload() : null;

            if ($payload === null) {
                break;
            }

            $result = $this->processPayload($payload, $handler);
            $this->sendResponse($rrWorker, $result);
        }
    }

    #[Override]
    public function shutdown(): void
    {
        $this->running = false;

        if ($this->worker !== null && method_exists($this->worker, 'stop')) {
            $this->worker->stop();
            $this->worker = null;
        }
    }

    #[Override]
    public function name(): string
    {
        return 'roadrunner';
    }

    #[Override]
    public function isAvailable(): bool
    {
        return class_exists(Worker::class)
            && isset($_SERVER['RR_RPC'], $_SERVER['RR_RELAY']);
    }

    /**
     * Process a RoadRunner payload into a gRPC handler invocation.
     */
    private function processPayload(object $payload, GrpcRequestHandler $handler): InterceptorResult
    {
        /** @var string $body */
        $body = $payload->body ?? '';

        /** @var string $header */
        $header = $payload->header ?? '';

        $decoded = $this->decodeHeader($header);

        $method = $decoded['method'] ?? '';
        $metadata = $decoded['metadata'] ?? [];
        $peerIdentity = $decoded['peer_identity'] ?? null;

        return $handler->handle($method, $body, $metadata, $peerIdentity);
    }

    /**
     * Send the gRPC response back through RoadRunner.
     */
    private function sendResponse(object $worker, InterceptorResult $result): void
    {
        if (!$result->isOk()) {
            if (method_exists($worker, 'error')) {
                $worker->error($this->encodeError($result));
            }

            return;
        }

        if (method_exists($worker, 'respond')) {
            $response = new Payload(
                body: $result->payload,
                header: $this->encodeResponseHeader($result),
            );
            $worker->respond($response);
        }
    }

    /**
     * Decode the RoadRunner header into method name, metadata, and peer identity.
     *
     * @return array{method: string, metadata: array<string, list<string>>, peer_identity: string|null}
     */
    private function decodeHeader(string $header): array
    {
        if ($header === '') {
            return ['method' => '', 'metadata' => [], 'peer_identity' => null];
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($header, true);

        if (!is_array($data)) {
            return ['method' => '', 'metadata' => [], 'peer_identity' => null];
        }

        /** @var mixed $rawMethod */
        $rawMethod = $data['method'] ?? null;
        $method = is_string($rawMethod) ? $rawMethod : '';

        /** @var array<string, list<string>> $metadata */
        $metadata = [];
        /** @var mixed $rawMetadata */
        $rawMetadata = $data['metadata'] ?? null;
        if (is_array($rawMetadata)) {
            /** @var mixed $values */
            foreach ($rawMetadata as $key => $values) {
                if (is_string($key) && is_array($values)) {
                    $metadata[$key] = array_values(array_filter($values, 'is_string'));
                }
            }
        }

        // Only trust peer_identity when trustedProxy is enabled: this means
        // RoadRunner is configured to extract it from the TLS handshake.
        /** @var string|null $peerIdentity */
        $peerIdentity = null;
        if ($this->trustedProxy && is_string($data['peer_identity'] ?? null)) {
            $peerIdentity = (string) $data['peer_identity'];
        }

        return ['method' => $method, 'metadata' => $metadata, 'peer_identity' => $peerIdentity];
    }

    /**
     * Encode a gRPC error result for RoadRunner's error protocol.
     */
    private function encodeError(InterceptorResult $result): string
    {
        return (string) json_encode([
            'code' => $result->status->value,
            'message' => $result->message,
            'metadata' => $result->trailers,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Encode the response header (trailing metadata) for RoadRunner.
     */
    private function encodeResponseHeader(InterceptorResult $result): string
    {
        if ($result->trailers === []) {
            return '';
        }

        return (string) json_encode($result->trailers, JSON_THROW_ON_ERROR);
    }
}
