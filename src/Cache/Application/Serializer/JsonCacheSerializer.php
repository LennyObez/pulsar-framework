<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Serializer;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Exception\CacheException;

use function is_object;
use function json_decode;
use function json_encode;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * JSON-based cache serializer (default, safe-by-default for regulated domains).
 *
 * Supports scalars and arrays only. Objects produce an actionable error message
 * directing the user to configure the PHP serializer for their pool.
 */
#[Internal]
final class JsonCacheSerializer implements CacheSerializerInterface
{
    private const int ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    public function serialize(mixed $value): string
    {
        if (is_object($value)) {
            throw CacheException::serializationFailed(
                'Default cache serializer is JSON (scalars and arrays only). '
                . 'To cache objects, configure `serializer: \'php\'` on the pool '
                . 'and review the security notes in docs/cache.md.',
            );
        }

        try {
            return json_encode($value, self::ENCODE_FLAGS);
        } catch (JsonException $e) {
            throw CacheException::serializationFailed($e->getMessage(), $e);
        }
    }

    public function deserialize(string $data): mixed
    {
        try {
            return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw CacheException::serializationFailed($e->getMessage(), $e);
        }
    }
}
