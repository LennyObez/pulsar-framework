<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Cache;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;

use function sprintf;

#[Internal(reason: 'Caching decorator for settings; use SettingsServiceInterface')]
final readonly class CachedSettingsService implements SettingsServiceInterface
{
    private const int TTL = 300;
    private const string TAG = 'cms_settings';

    public function __construct(
        private SettingsServiceInterface $inner,
        private TaggedCacheInterface $cache,
    ) {}

    #[Override]
    public function get(string $group, string $key, ?string $locale = null): mixed
    {
        $cacheKey = sprintf('cms_settings:%s:%s:%s', $group, $key, $locale ?? '_');
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $value = $this->inner->get($group, $key, $locale);
        $this->cache->set($cacheKey, $value, [self::TAG], self::TTL);

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
        $this->cache->invalidateTag(self::TAG);
    }

    #[Override]
    public function getGroup(string $group, ?string $locale = null): array
    {
        $cacheKey = sprintf('cms_settings_group:%s:%s', $group, $locale ?? '_');
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            /** @var array<string, mixed> $cached */
            return $cached;
        }

        $result = $this->inner->getGroup($group, $locale);
        $this->cache->set($cacheKey, $result, [self::TAG], self::TTL);

        return $result;
    }

    #[Override]
    public function getAll(?string $locale = null): array
    {
        $cacheKey = sprintf('cms_settings_all:%s', $locale ?? '_');
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            /** @var array<string, array<string, mixed>> $cached */
            return $cached;
        }

        $result = $this->inner->getAll($locale);
        $this->cache->set($cacheKey, $result, [self::TAG], self::TTL);

        return $result;
    }
}
