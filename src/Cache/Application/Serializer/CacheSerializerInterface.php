<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Serializer;

use Pulsar\Api\Api;
use Pulsar\Cache\Application\Exception\CacheException;

/**
 * Contract for cache value serialization.
 * @api
 */
#[Api(since: '1.0.0')]
interface CacheSerializerInterface
{
    /**
     * Serialize a value to a string for storage.
     *
     * @throws CacheException If the value cannot be serialized
     */
    public function serialize(mixed $value): string;

    /**
     * Deserialize a string back to the original value.
     *
     * @throws CacheException If the string cannot be deserialized
     */
    public function deserialize(string $data): mixed;
}
