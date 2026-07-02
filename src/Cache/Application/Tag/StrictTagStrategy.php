<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Tag;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;

use function array_map;
use function hrtime;

/**
 * Strict tag strategy using atomic increment for generational versioning.
 *
 * Requires a driver with atomic increment support (Redis, Database).
 * Guarantees no stale reads after tag invalidation.
 */
#[Internal]
final class StrictTagStrategy implements TagStrategyInterface
{
    private const string TAG_KEY_PREFIX = '_tag:';
    private const string TAG_KEY_SUFFIX = ':ver';

    public function __construct(
        private readonly CacheDriverInterface $driver,
    ) {}

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
                // Initialize with a fresh monotonic version. A literal constant
                // (e.g. '1') would collide with a previously stored snapshot if
                // the version key were evicted and reinitialized after an
                // invalidateTag(): the stale item, tagged at the same constant,
                // would match the reset value and be served as a hit. hrtime()
                // never repeats, matching the fallback in invalidateTag() below.
                $version = (string) hrtime(true);
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
        $result = $this->driver->increment($key);

        if ($result === false) {
            // Fallback: set a new version if increment fails
            $this->driver->set($key, (string) hrtime(true), null);
        }
    }

    public function invalidateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->invalidateTag($tag);
        }
    }

    private static function tagKey(string $tag): string
    {
        return self::TAG_KEY_PREFIX . $tag . self::TAG_KEY_SUFFIX;
    }
}
