<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Cache;

use Pulsar\Api\Internal;

use function hash;
use function preg_match;
use function sprintf;

/**
 * Single source of truth for every CMS cache key and tag.
 *
 * The application cache validates keys and tags against the PSR-6 reserved
 * characters `{}()/\@:` (see CacheKeyValidator) BEFORE any storage call, so a
 * colon- or slash-bearing key is an InvalidArgumentException on the first
 * get/set — not a miss. The CMS historically formatted keys with ':' and put
 * raw URL paths (containing '/') into them, which made the whole CMS cache
 * layer throw the moment the application cache was enabled. Centralising the
 * formats here (dot separators, free-form components made safe by {@see part()})
 * fixes that and gives the regression test one surface to hold.
 *
 * Writers (CachedContentRepository, CachedMenuRepository, CachedSettingsService,
 * CmsPageCacheMiddleware, rate limiters) and the invalidator
 * (CmsCacheInvalidator) MUST build keys and tags through this class so the tag
 * a writer attaches and the tag the invalidator bumps can never drift apart.
 */
#[Internal(reason: 'CMS cache key registry; not a public API surface')]
final readonly class CmsCacheKeys
{
    /** Coarse tag on every cached page entry. */
    public const string TAG_ALL_PAGES = 'cms_pages';

    /** Coarse tag on every cached content entry. */
    public const string TAG_ALL_CONTENT = 'cms_content';

    /** Coarse tag on every cached menu entry. */
    public const string TAG_ALL_MENUS = 'cms_menu';

    /** Tag on cached resolved menu-item lists. */
    public const string TAG_MENU_ITEMS = 'cms_menu_items';

    /** Tag on every cached settings entry. */
    public const string TAG_SETTINGS = 'cms_settings';

    /** Tag on rate-limit counters. */
    public const string TAG_RATE_LIMIT = 'cms_rate_limit';

    /** Tag on comment duplicate-detection markers. */
    public const string TAG_COMMENT_DEDUP = 'cms_comment_dedup';

    /** Tag on public rate-limit counters. */
    public const string TAG_PUBLIC_RATE = 'cms_public_rate';

    /** Tag on comment rate-limit counters. */
    public const string TAG_COMMENT_RATE = 'cms_comment_rate';

    /** Key prefix shared by every cached page entry (Studio inspector filter). */
    public const string PAGE_KEY_PREFIX = 'cms_page.';

    public static function contentId(string $id): string
    {
        return 'cms_content_id.' . self::part($id);
    }

    public static function contentPath(string $locale, string $path, ?string $tenantId): string
    {
        return sprintf(
            'cms_content_path.%s.%s.%s',
            self::part($locale),
            self::part($tenantId ?? '_'),
            self::part($path),
        );
    }

    public static function contentTag(string $id): string
    {
        return 'cms_content.' . self::part($id);
    }

    public static function menu(string $location, string $locale, ?string $tenantId): string
    {
        return sprintf(
            'cms_menu.%s.%s.%s',
            self::part($location),
            self::part($locale),
            self::part($tenantId ?? '_'),
        );
    }

    public static function menuItems(string $menuId, string $locale): string
    {
        return sprintf('cms_menu_items.%s.%s', self::part($menuId), self::part($locale));
    }

    public static function menuTag(string $location): string
    {
        return 'cms_menu.' . self::part($location);
    }

    public static function typeTag(string $contentType): string
    {
        return 'cms_type.' . self::part($contentType);
    }

    public static function setting(string $group, string $key, ?string $locale): string
    {
        return sprintf(
            'cms_settings.%s.%s.%s',
            self::part($group),
            self::part($key),
            self::part($locale ?? '_'),
        );
    }

    public static function settingsGroup(string $group, ?string $locale): string
    {
        return sprintf('cms_settings_group.%s.%s', self::part($group), self::part($locale ?? '_'));
    }

    public static function settingsAll(?string $locale): string
    {
        return 'cms_settings_all.' . self::part($locale ?? '_');
    }

    /**
     * Full-page cache entry key. The digest is a collision-resistant hash of
     * the request identity (host, path, filtered query) computed by the page
     * cache middleware; tenant and locale stay readable for operability.
     */
    public static function page(string $tenantId, string $locale, string $digest): string
    {
        return sprintf(
            '%s%s.%s.%s',
            self::PAGE_KEY_PREFIX,
            self::part($tenantId),
            self::part($locale),
            self::part($digest),
        );
    }

    public static function previewSession(string $token): string
    {
        return 'cms_preview_session.' . self::part($token);
    }

    /**
     * Rate-limit counter for an operation/actor key such as
     * "backup_create:<user_id>" — free-form by contract, so part() makes it
     * storage-safe.
     */
    public static function rate(string $key, int $window): string
    {
        return sprintf('cms_rate.%s.%d', self::part($key), $window);
    }

    /**
     * Lock resource guarding {@see rate()}'s read-modify-write. Lock resources
     * bypass CacheKeyValidator, but sharing the same safe grammar keeps every
     * CMS cache identifier uniform.
     */
    public static function rateLock(string $key, int $window): string
    {
        return sprintf('cms_rate_lock.%s.%d', self::part($key), $window);
    }

    public static function commentRateMinute(string $ipHash, int $bucket): string
    {
        return sprintf('cms_comment_rate.%s.min.%d', self::part($ipHash), $bucket);
    }

    public static function commentRateHour(string $ipHash, int $bucket): string
    {
        return sprintf('cms_comment_rate.%s.hour.%d', self::part($ipHash), $bucket);
    }

    public static function publicRate(string $group, string $ipHash, int $bucket): string
    {
        return sprintf('cms_public_rate.%s.%s.%d', self::part($group), self::part($ipHash), $bucket);
    }

    public static function commentDedup(string $ipHash, string $bodyHash): string
    {
        return sprintf('cms_comment_dedup.%s.%s', self::part($ipHash), self::part($bodyHash));
    }

    /**
     * Make one key component storage-safe and injective.
     *
     * Components matching a conservative grammar (alphanumerics, underscore,
     * hyphen, at most 64 chars) pass through readable; anything else — URL
     * paths with '/', caller keys with ':', overlong values — is replaced by
     * 'x' + sha256. Injectivity holds because a hashed part is 65 characters,
     * one more than the readable grammar allows, so the readable set and the
     * hashed set are disjoint and two distinct inputs can never collide.
     */
    private static function part(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) === 1) {
            return $value;
        }

        return 'x' . hash('sha256', $value);
    }
}
