<?php

declare(strict_types=1);

namespace Pulsar\Cache;

use Pulsar\Api\Internal;
use Random\RandomException;
use SodiumException;

use function is_array;

/**
 * Optimization-hint cache for constructor parameter type maps.
 *
 * This is strictly a hint: the container uses it to skip reflection
 * when possible, but falls back to reflection for any class not found
 * in the cache or where the cached entry causes a resolution failure.
 *
 * Never cached: union types, intersection types, self/static/parent, variadic params.
 */
#[Internal]
final class ContainerCache
{
    public const string FILENAME = 'container.cache.bin';

    /** Schema version: incremented when resolution rules change. */
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        private readonly CacheIntegrity $integrity,
    ) {}

    /**
     * Write container resolution hints to a cache file.
     *
     * @param array<class-string, list<array{name: string, type: class-string}>> $hints
     *
     * @throws CacheException
     * @throws SodiumException
     * @throws RandomException If nonce generation fails during encryption
     */
    public function write(string $cachePath, array $hints, bool $encrypt): void
    {
        $path = $cachePath . DIRECTORY_SEPARATOR . self::FILENAME;

        $data = [
            'schema' => self::SCHEMA_VERSION,
            'hints' => $hints,
        ];

        $serialized = serialize($data);
        $this->integrity->writeEnvelope($path, $serialized, $encrypt);
    }

    /**
     * Load container resolution hints from a cache file.
     *
     * @param list<class-string> $allowedClasses
     * @return array<class-string, list<array{name: string, type: class-string}>>|null
     *
     * @throws CacheException
     * @throws SodiumException
     */
    public function load(string $cachePath, array $allowedClasses): ?array
    {
        $path = $cachePath . DIRECTORY_SEPARATOR . self::FILENAME;

        $result = $this->integrity->readEnvelope($path, $allowedClasses);

        if (!is_array($result)) {
            return null;
        }

        if (($result['schema'] ?? null) !== self::SCHEMA_VERSION) {
            return null;
        }

        /** @var array<class-string, list<array{name: string, type: class-string}>> */
        return $result['hints'] ?? null;
    }
}
