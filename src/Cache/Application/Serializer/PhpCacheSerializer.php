<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Serializer;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Exception\CacheException;
use Throwable;

use function serialize;
use function unserialize;

/**
 * PHP native serializer (opt-in, for caching objects).
 *
 * By default, no classes are allowed during deserialization.
 * Pass an explicit allowlist to the constructor to permit specific classes.
 */
#[Internal]
final readonly class PhpCacheSerializer implements CacheSerializerInterface
{
    /**
     * @param list<class-string> $allowedClasses Classes allowed during deserialization
     */
    public function __construct(
        private array $allowedClasses = [],
    ) {}

    public function serialize(mixed $value): string
    {
        try {
            return serialize($value);
        } catch (Throwable $e) {
            throw CacheException::serializationFailed($e->getMessage(), $e);
        }
    }

    public function deserialize(string $data): mixed
    {
        try {
            $result = unserialize($data, [
                'allowed_classes' => $this->allowedClasses === [] ? false : $this->allowedClasses,
            ]);
        } catch (Throwable $e) {
            throw CacheException::serializationFailed($e->getMessage(), $e);
        }

        if ($result === false && $data !== 'b:0;') {
            throw CacheException::serializationFailed('Failed to unserialize cached data');
        }

        return $result;
    }
}
