<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\CacheKeyValidator;
use Pulsar\Extension\Cms\Internal\Cache\CmsCacheKeys;

use function hash;

#[CoversClass(CmsCacheKeys::class)]
final class CmsCacheKeysTest extends TestCase
{
    /**
     * Every key and tag the CMS can emit must pass the PSR-6 validator, even
     * when built from hostile components (colons, slashes, overlong values).
     * The historical ':' formats threw InvalidArgumentException on the first
     * get/set the moment the application cache was enabled — this test is the
     * regression fence.
     */
    #[Test]
    public function everyKeyFamilySurvivesTheCacheKeyValidator(): void
    {
        $hostile = 'a:b/c\\d{e}(f)@g';
        $overlong = str_repeat('a', 300);

        $keys = [
            CmsCacheKeys::contentId('0198f7c2-uuid'),
            CmsCacheKeys::contentId($hostile),
            CmsCacheKeys::contentPath('fr', 'a-propos/equipe', null),
            CmsCacheKeys::contentPath('fr', $overlong, 'tenant-a'),
            CmsCacheKeys::contentTag('42'),
            CmsCacheKeys::contentTag($hostile),
            CmsCacheKeys::menu('main', 'en', 'tenant-a'),
            CmsCacheKeys::menu($hostile, 'en', null),
            CmsCacheKeys::menuItems('m1', 'en'),
            CmsCacheKeys::menuTag('footer'),
            CmsCacheKeys::typeTag('article'),
            CmsCacheKeys::setting('seo', 'title', 'fr'),
            CmsCacheKeys::setting($hostile, $hostile, $hostile),
            CmsCacheKeys::settingsGroup('seo', null),
            CmsCacheKeys::settingsAll('en'),
            CmsCacheKeys::page('default', 'fr', hash('sha256', 'x')),
            CmsCacheKeys::previewSession('tok:with:colons'),
            CmsCacheKeys::rate('backup_create:user-9', 12345),
            CmsCacheKeys::rateLock('backup_create:user-9', 12345),
            CmsCacheKeys::commentRateMinute(hash('xxh128', 'ip'), 1),
            CmsCacheKeys::commentRateHour(hash('xxh128', 'ip'), 1),
            CmsCacheKeys::publicRate('feed', hash('xxh128', 'ip'), 9),
            CmsCacheKeys::commentDedup(hash('xxh128', 'ip'), hash('xxh128', 'body')),
            CmsCacheKeys::TAG_ALL_PAGES,
            CmsCacheKeys::TAG_ALL_CONTENT,
            CmsCacheKeys::TAG_ALL_MENUS,
            CmsCacheKeys::TAG_MENU_ITEMS,
            CmsCacheKeys::TAG_SETTINGS,
            CmsCacheKeys::TAG_RATE_LIMIT,
            CmsCacheKeys::TAG_COMMENT_DEDUP,
            CmsCacheKeys::TAG_PUBLIC_RATE,
            CmsCacheKeys::TAG_COMMENT_RATE,
        ];

        foreach ($keys as $key) {
            CacheKeyValidator::validate($key);
        }

        self::assertCount(32, $keys);
    }

    #[Test]
    public function safeComponentsStayReadable(): void
    {
        self::assertSame('cms_content_id.abc-123', CmsCacheKeys::contentId('abc-123'));
        self::assertSame('cms_settings.site.title._', CmsCacheKeys::setting('site', 'title', null));
        self::assertSame('cms_menu.footer', CmsCacheKeys::menuTag('footer'));
    }

    #[Test]
    public function hostileComponentsAreHashedInjectively(): void
    {
        // Distinct hostile inputs must produce distinct keys, and a hostile
        // input must never collide with a readable one ('a:b' vs 'a-b').
        self::assertNotSame(CmsCacheKeys::contentTag('a:b'), CmsCacheKeys::contentTag('a-b'));
        self::assertNotSame(CmsCacheKeys::contentTag('a:b'), CmsCacheKeys::contentTag('a/b'));

        // Deterministic: the same input always maps to the same key.
        self::assertSame(CmsCacheKeys::contentTag('a:b'), CmsCacheKeys::contentTag('a:b'));
    }

    #[Test]
    public function writerAndInvalidatorTagFormatsAgree(): void
    {
        // The tag CachedContentRepository attaches and the tag
        // CmsCacheInvalidator bumps come from the same factory, so they can
        // never drift apart — this pins the shared format.
        self::assertSame('cms_content.post-9', CmsCacheKeys::contentTag('post-9'));
        self::assertSame('cms_menu.header', CmsCacheKeys::menuTag('header'));
    }
}
