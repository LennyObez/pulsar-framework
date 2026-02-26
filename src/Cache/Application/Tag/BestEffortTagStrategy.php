<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Tag;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Random\Engine\Secure;
use Random\Randomizer;

use function array_map;
use function bin2hex;

/**
 * Best-effort tag strategy using random version strings.
 *
 * For drivers without atomic increment (Memcached, APCu, Filesystem).
 * Uses random strings instead of atomic counters for version bumps.
 *
 * Documented race window: a read concurrent with an invalidation can
 * observe a stale value exactly once. Tag version key eviction is
 * treated as full invalidation (conservative miss).
 */
#[Internal]
final class BestEffortTagStrategy implements TagStrategyInterface
{
    private const string TAG_KEY_PREFIX = '_tag:';
    private const string TAG_KEY_SUFFIX = ':ver';

    private readonly Randomizer $randomizer;

    public function __construct(
        private readonly CacheDriverInterface $driver,
    ) {
        $this->randomizer = new Randomizer(new Secure());
    }

    public function getTagVersions(array $tags): array
    {
        if ($tags === []) {
            return [];
        }

        $tagKeys = array_map(self::tagKey(...), $tags);
        $rawValues = $this->driver->getMultiple($tagKeys);

        $versions = [];

        foreach ($tags as $i => $tag) {
            $key = $tagKeys[$i];
            $value = $rawValues[$key] ?? null;

            if ($value === null) {
                // Initialize with a random version
                $version = $this->generateVersion();
                $this->driver->set($key, $version, null);
                $versions[$tag] = $version;
            } else {
                $versions[$tag] = $value;
            }
        }

        return $versions;
    }

    public function invalidateTag(string $tag): void
    {
        $key = self::tagKey($tag);
        $this->driver->set($key, $this->generateVersion(), null);
    }

    public function invalidateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->invalidateTag($tag);
        }
    }

    private function generateVersion(): string
    {
        return bin2hex($this->randomizer->getBytes(8));
    }

    private static function tagKey(string $tag): string
    {
        return self::TAG_KEY_PREFIX . $tag . self::TAG_KEY_SUFFIX;
    }
}
