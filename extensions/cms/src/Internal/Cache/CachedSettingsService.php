<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Cache;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;

/**
 * @psalm-api Caching decorator wrapping the underlying SettingsServiceInterface
 *            implementation; bound by the CMS service provider, not instantiated by name.
 */
#[Internal(reason: 'Caching decorator for settings; use SettingsServiceInterface')]
final readonly class CachedSettingsService implements SettingsServiceInterface
{
    private const int TTL = 300;

    public function __construct(
        private SettingsServiceInterface $inner,
        private TaggedCacheInterface $cache,
        private CmsCacheInvalidator $invalidator,
    ) {}

    #[Override]
    public function get(string $group, string $key, ?string $locale = null): mixed
    {
        $cacheKey = CmsCacheKeys::setting($group, $key, $locale);
        /** @var mixed $cached */
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        /** @var mixed $value */
        $value = $this->inner->get($group, $key, $locale);
        $this->cache->set($cacheKey, $value, [CmsCacheKeys::TAG_SETTINGS], self::TTL);

        return $value;
    }

    #[Override]
    public function set(
        string $group,
        string $key,
        mixed $value,
        ?string $locale = null,
        ?string $reason = null,
    ): void {
        $this->inner->set($group, $key, $value, $locale, $reason);
        $this->invalidator->invalidateSettings();
    }

    #[Override]
    public function getGroup(string $group, ?string $locale = null): array
    {
        $cacheKey = CmsCacheKeys::settingsGroup($group, $locale);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            /** @var array<string, mixed> $cached */
            return $cached;
        }

        $result = $this->inner->getGroup($group, $locale);
        $this->cache->set($cacheKey, $result, [CmsCacheKeys::TAG_SETTINGS], self::TTL);

        return $result;
    }

    #[Override]
    public function getAll(?string $locale = null): array
    {
        $cacheKey = CmsCacheKeys::settingsAll($locale);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            /** @var array<string, array<string, mixed>> $cached */
            return $cached;
        }

        $result = $this->inner->getAll($locale);
        $this->cache->set($cacheKey, $result, [CmsCacheKeys::TAG_SETTINGS], self::TTL);

        return $result;
    }
}
