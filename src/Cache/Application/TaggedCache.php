<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application;

use Pulsar\Api\Api;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Event\CacheEventEmitter;
use Pulsar\Cache\Application\Exception\CacheException;
use Pulsar\Cache\Application\Serializer\CacheSerializerInterface;
use Pulsar\Cache\Application\Tag\TagStrategyInterface;
use Throwable;

use function array_keys;
use function hrtime;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Tag-based cache wrapping a driver + tag strategy.
 *
 * Stores tag version snapshots with each item.
 * Validates tag versions on read — stale items are treated as misses.
 */
#[Api(since: '1.0.0')]
final class TaggedCache implements TaggedCacheInterface
{
    public function __construct(
        private readonly string $poolName,
        private readonly CacheDriverInterface $driver,
        private readonly CacheSerializerInterface $serializer,
        private readonly TagStrategyInterface $tagStrategy,
        private readonly CacheEventEmitter $eventEmitter,
        private readonly ?int $defaultTtlSeconds = null,
        private readonly bool $critical = false,
    ) {}

    /**
     * @throws CacheException On driver failure in critical mode
     */
    public function get(string $key): mixed
    {
        CacheKeyValidator::validate($key);
        $start = hrtime(true);

        try {
            $raw = $this->driver->get($key);

            if ($raw === null) {
                $this->eventEmitter->emitMiss($this->poolName, $this->driver->name(), $key, $start);
                return null;
            }

            /** @var array{v: mixed, tags: array<string, string>}|null $envelope */
            $envelope = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);

            if ($envelope === null || !isset($envelope['v'], $envelope['tags'])) {
                $this->eventEmitter->emitMiss($this->poolName, $this->driver->name(), $key, $start);
                return null;
            }

            // Validate tag versions
            /** @var array<string, string> $storedVersions */
            $storedVersions = $envelope['tags'];

            if ($storedVersions !== []) {
                $currentVersions = $this->tagStrategy->getTagVersions(array_keys($storedVersions));

                foreach ($storedVersions as $tag => $version) {
                    if (!isset($currentVersions[$tag]) || $currentVersions[$tag] !== $version) {
                        $this->eventEmitter->emitMiss($this->poolName, $this->driver->name(), $key, $start);
                        return null;
                    }
                }
            }

            /** @var string $serializedValue */
            $serializedValue = $envelope['v'];
            $value = $this->serializer->deserialize($serializedValue);
            $this->eventEmitter->emitHit($this->poolName, $this->driver->name(), $key, $start);

            return $value;
        } catch (CacheException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->handleError($key, $start, $e);
        }
    }

    /**
     * @param list<string> $tags
     *
     * @throws CacheException On driver failure in critical mode
     */
    public function set(string $key, mixed $value, array $tags, ?int $ttlSeconds = null): bool
    {
        CacheKeyValidator::validate($key);
        CacheKeyValidator::validateMultiple($tags);

        $ttl = $ttlSeconds ?? $this->defaultTtlSeconds;
        $start = hrtime(true);

        try {
            $serialized = $this->serializer->serialize($value);
            $tagVersions = $tags !== [] ? $this->tagStrategy->getTagVersions($tags) : [];

            $envelope = json_encode([
                'v' => $serialized,
                'tags' => $tagVersions,
            ], JSON_THROW_ON_ERROR);

            $result = $this->driver->set($key, $envelope, $ttl);
            $this->eventEmitter->emitWrite($this->poolName, $this->driver->name(), $key, $start);

            return $result;
        } catch (CacheException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->handleError($key, $start, $e);
            return false;
        }
    }

    /**
     * @throws CacheException On driver failure in critical mode
     */
    public function delete(string $key): bool
    {
        CacheKeyValidator::validate($key);
        $start = hrtime(true);

        try {
            $result = $this->driver->delete($key);
            $this->eventEmitter->emitDelete($this->poolName, $this->driver->name(), $key, $start);

            return $result;
        } catch (Throwable $e) {
            $this->handleError($key, $start, $e);
            return false;
        }
    }

    public function invalidateTag(string $tag): void
    {
        CacheKeyValidator::validate($tag);
        $this->tagStrategy->invalidateTag($tag);
    }

    public function invalidateTags(array $tags): void
    {
        CacheKeyValidator::validateMultiple($tags);
        $this->tagStrategy->invalidateTags($tags);
    }

    private function handleError(string $key, int $startNs, Throwable $e): mixed
    {
        $this->eventEmitter->emitError(
            $this->poolName,
            $this->driver->name(),
            $key,
            $startNs,
            $e->getMessage(),
            $e,
        );

        if ($this->critical) {
            throw CacheException::driverError($this->driver->name(), $e->getMessage(), $e);
        }

        return null;
    }
}
