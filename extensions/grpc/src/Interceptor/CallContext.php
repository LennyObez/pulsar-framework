<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Interceptor;

use Pulsar\Api\Api;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;

/**
 * Immutable context for a gRPC call passing through the interceptor pipeline.
 *
 * Carries method metadata, request payload, headers, deadline, and
 * client identity information. Interceptors may produce new contexts
 * with additional attributes via withAttribute().
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CallContext
{
    /**
     * @param MethodDescriptor $method         The target RPC method
     * @param string $payload                  Serialized request payload (protobuf bytes)
     * @param array<string, list<string>> $metadata  gRPC metadata (headers)
     * @param float|null $deadline             Absolute deadline as Unix timestamp with microseconds
     * @param string|null $peerIdentity        Client identity from mTLS certificate SAN
     * @param array<string, mixed> $attributes Arbitrary attributes set by interceptors
     */
    public function __construct(
        public MethodDescriptor $method,
        public string $payload,
        public array $metadata = [],
        public ?float $deadline = null,
        public ?string $peerIdentity = null,
        public array $attributes = [],
    ) {}

    /**
     * Return a new context with an additional attribute.
     */
    public function withAttribute(string $key, mixed $value): self
    {
        return clone($this, ['attributes' => [...$this->attributes, $key => $value]]);
    }

    /**
     * Return a new context with updated metadata.
     *
     * @param array<string, list<string>> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return clone($this, ['metadata' => $metadata]);
    }

    /**
     * Get a single metadata value (first entry for the key).
     */
    public function getMetadataValue(string $key): ?string
    {
        $values = $this->metadata[$key] ?? [];

        return $values[0] ?? null;
    }

    /**
     * Whether the call deadline has been exceeded.
     */
    public function isDeadlineExceeded(): bool
    {
        if ($this->deadline === null) {
            return false;
        }

        return microtime(true) > $this->deadline;
    }
}
