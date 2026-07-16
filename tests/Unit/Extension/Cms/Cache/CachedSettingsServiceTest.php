<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Internal\Cache\CachedSettingsService;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;

#[CoversClass(CachedSettingsService::class)]
final class CachedSettingsServiceTest extends TestCase
{
    private SettingsServiceInterface&Stub $inner;
    private TaggedCacheInterface&Stub $cache;
    private CachedSettingsService $sut;

    protected function setUp(): void
    {
        $this->inner = $this->createStub(SettingsServiceInterface::class);
        $this->cache = $this->createStub(TaggedCacheInterface::class);
        $this->sut = new CachedSettingsService($this->inner, $this->cache);
    }

    #[Test]
    public function get_returns_cached_value_on_hit(): void
    {
        $this->cache->method('get')->willReturn('cached-value');

        $result = $this->sut->get('site', 'title');

        self::assertSame('cached-value', $result);
    }

    #[Test]
    public function get_delegates_to_inner_on_cache_miss(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->inner->method('get')->willReturn('inner-value');

        $result = $this->sut->get('site', 'title');

        self::assertSame('inner-value', $result);
    }

    #[Test]
    public function get_stores_value_in_cache_on_miss(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->inner->method('get')->willReturn('fresh-value');

        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects(self::once())
            ->method('set')
            ->with('cms_settings.site.title._', 'fresh-value', ['cms_settings'], 300);

        $sut = new CachedSettingsService($this->inner, $cache);
        $sut->get('site', 'title');
    }

    #[Test]
    public function set_invalidates_cache_tag(): void
    {
        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->expects(self::once())
            ->method('invalidateTag')
            ->with('cms_settings');

        $sut = new CachedSettingsService($this->inner, $cache);
        $sut->set('site', 'title', 'New Title');
    }

    #[Test]
    public function set_delegates_to_inner(): void
    {
        $inner = $this->createMock(SettingsServiceInterface::class);
        $inner->expects(self::once())
            ->method('set')
            ->with('site', 'title', 'New Title', 'en', 'Updated');

        $sut = new CachedSettingsService($inner, $this->cache);
        $sut->set('site', 'title', 'New Title', 'en', 'Updated');
    }

    #[Test]
    public function getGroup_returns_cached_value_on_hit(): void
    {
        $expected = ['title' => 'My Site', 'tagline' => 'Hello'];
        $this->cache->method('get')->willReturn($expected);

        $result = $this->sut->getGroup('site');

        self::assertSame($expected, $result);
    }

    #[Test]
    public function getGroup_delegates_to_inner_on_miss(): void
    {
        $expected = ['title' => 'My Site'];
        $this->cache->method('get')->willReturn(null);
        $this->inner->method('getGroup')->willReturn($expected);

        $result = $this->sut->getGroup('site');

        self::assertSame($expected, $result);
    }

    #[Test]
    public function getAll_returns_cached_value_on_hit(): void
    {
        $expected = ['site' => ['title' => 'My Site']];
        $this->cache->method('get')->willReturn($expected);

        $result = $this->sut->getAll();

        self::assertSame($expected, $result);
    }

    #[Test]
    public function getAll_delegates_to_inner_on_miss(): void
    {
        $expected = ['site' => ['title' => 'My Site']];
        $this->cache->method('get')->willReturn(null);
        $this->inner->method('getAll')->willReturn($expected);

        $result = $this->sut->getAll();

        self::assertSame($expected, $result);
    }

    #[Test]
    public function get_uses_locale_in_cache_key(): void
    {
        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects(self::once())
            ->method('set')
            ->with('cms_settings.site.title.fr', 'Titre', ['cms_settings'], 300);

        $this->inner->method('get')->willReturn('Titre');

        $sut = new CachedSettingsService($this->inner, $cache);
        $sut->get('site', 'title', 'fr');
    }
}
