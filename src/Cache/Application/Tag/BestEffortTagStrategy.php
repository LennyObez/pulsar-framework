<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Tag;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Runtime\ResettableInterface;
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
 *
 * Tag versions are memoized per REQUEST, so a tag consulted by several tagged
 * reads costs a single driver round-trip rather than one per read — the
 * amortization ADR-0018 promises. The memo is kept coherent on local
 * invalidation (the fresh version is stored). On persistent runtimes the
 * instance outlives the request (CacheManager memoizes TaggedCache singletons),
 * so the memo MUST be cleared between requests via {@see resetRequestState()}
 * — otherwise another worker's invalidation is never observed and this worker
 * keeps serving (and re-tagging writes with) a dead version for the worker's
 * whole lifetime. CacheManager propagates the reset through the
 * RequestResetRegistry.
 */
#[Internal]
final class BestEffortTagStrategy implements TagStrategyInterface, ResettableInterface
{
    private const string TAG_KEY_PREFIX = '_tag:';
    private const string TAG_KEY_SUFFIX = ':ver';

    private readonly Randomizer $randomizer;

    /** @var array<string, string> Memoized tag => version for this instance. */
    private array $versionMemo = [];

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

        // Only the tags not already memoized need a driver round-trip.
        $missing = [];
        foreach ($tags as $tag) {
            if (!isset($this->versionMemo[$tag])) {
                $missing[] = $tag;
            }
        }

        if ($missing !== []) {
            $missingKeys = array_map(self::tagKey(...), $missing);
            $rawValues = $this->driver->getMultiple($missingKeys);

            foreach ($missing as $i => $tag) {
                $value = $rawValues[$missingKeys[$i]] ?? null;

                if ($value === null) {
                    // Initialize with a random version and persist it.
                    $value = $this->generateVersion();
                    $this->driver->set($missingKeys[$i], $value, null);
                }

                $this->versionMemo[$tag] = $value;
            }
        }

        $versions = [];
        foreach ($tags as $tag) {
            $versions[$tag] = $this->versionMemo[$tag];
        }

        return $versions;
    }

    public function invalidateTag(string $tag): void
    {
        $key = self::tagKey($tag);
        $version = $this->generateVersion();
        $this->driver->set($key, $version, null);

        // Keep the memo coherent: later reads in this request must see the
        // freshly bumped version, not the pre-invalidation one.
        $this->versionMemo[$tag] = $version;
    }

    public function invalidateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->invalidateTag($tag);
        }
    }

    /**
     * Clear the per-request tag-version memo so the next request re-reads
     * versions from the driver and observes invalidations made by other
     * workers. Called between requests on persistent runtimes.
     */
    #[Override]
    public function resetRequestState(): void
    {
        $this->versionMemo = [];
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
