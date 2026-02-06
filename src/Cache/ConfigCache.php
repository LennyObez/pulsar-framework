<?php

declare(strict_types=1);

namespace Pulsar\Cache;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigRepository;

/**
 * Serializes and deserializes ConfigRepository with HMAC-protected payload.
 */
#[Internal]
final class ConfigCache
{
    public const string FILENAME = 'config.cache.bin';

    public function __construct(
        private readonly CacheIntegrity $integrity,
    ) {}

    /**
     * Write the config repository to a cache file.
     */
    public function write(string $cachePath, ConfigRepository $repository, bool $encrypt): void
    {
        $path = $cachePath . DIRECTORY_SEPARATOR . self::FILENAME;
        $serialized = serialize($repository);
        $this->integrity->writeEnvelope($path, $serialized, $encrypt);
    }

    /**
     * Load the config repository from a cache file.
     *
     * @param list<class-string> $allowedClasses
     */
    public function load(string $cachePath, array $allowedClasses): ?ConfigRepository
    {
        $path = $cachePath . DIRECTORY_SEPARATOR . self::FILENAME;

        $result = $this->integrity->readEnvelope($path, $allowedClasses);

        if ($result instanceof ConfigRepository) {
            return $result;
        }

        return null;
    }
}
