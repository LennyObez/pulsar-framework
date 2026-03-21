<?php

declare(strict_types=1);

namespace Pulsar\Queue\Serialization;

use Pulsar\Api\Api;

/**
 * Contract for job payload serializers.
 *
 * Implementations transform job data to and from a wire format (JSON, msgpack, etc.)
 * while enforcing type safety through the {@see TypeRegistry}.
 * @api
 */
#[Api(since: '1.0.0')]
interface SerializerInterface
{
    /**
     * Serialize arbitrary data into a string representation.
     */
    public function serialize(mixed $data): string;

    /**
     * Deserialize a string back into a typed object.
     *
     * @param string $data The serialized payload.
     * @param string $type The expected FQCN of the target type.
     */
    public function deserialize(string $data, string $type): mixed;

    /**
     * The MIME content type produced by this serializer.
     */
    public function contentType(): string;
}
