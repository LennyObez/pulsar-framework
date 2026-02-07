<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Extension\Cms\Settings\SiteSetting;

use function array_key_exists;

#[CoversClass(SiteSetting::class)]
final class SettingsTest extends TestCase
{
    private InMemorySettingsService $settingsService;

    protected function setUp(): void
    {
        $this->settingsService = new InMemorySettingsService();
    }

    #[Test]
    public function test_get_and_set_setting(): void
    {
        $this->settingsService->set('general', 'site_name', 'Pulsar CMS');
        $value = $this->settingsService->get('general', 'site_name');

        self::assertSame('Pulsar CMS', $value);
    }

    #[Test]
    public function test_locale_specific_setting_overrides_default(): void
    {
        // Set global default
        $this->settingsService->set('general', 'site_name', 'Pulsar CMS');

        // Set French override
        $this->settingsService->set('general', 'site_name', 'Pulsar CMS (FR)', locale: 'fr');

        // Global value is the default
        $globalValue = $this->settingsService->get('general', 'site_name');
        self::assertSame('Pulsar CMS', $globalValue);

        // French locale overrides
        $frValue = $this->settingsService->get('general', 'site_name', locale: 'fr');
        self::assertSame('Pulsar CMS (FR)', $frValue);

        // Non-overridden locale falls back to global
        $deValue = $this->settingsService->get('general', 'site_name', locale: 'de');
        self::assertSame('Pulsar CMS', $deValue);
    }

    #[Test]
    public function test_get_group_returns_all_settings_in_group(): void
    {
        $this->settingsService->set('seo', 'default_title_suffix', ' | Pulsar CMS');
        $this->settingsService->set('seo', 'robots_default', 'index, follow');
        $this->settingsService->set('seo', 'sitemap_enabled', true);
        $this->settingsService->set('general', 'site_name', 'Pulsar CMS');

        $seoSettings = $this->settingsService->getGroup('seo');

        self::assertCount(3, $seoSettings);
        self::assertSame(' | Pulsar CMS', $seoSettings['default_title_suffix']);
        self::assertSame('index, follow', $seoSettings['robots_default']);
        self::assertTrue($seoSettings['sitemap_enabled']);

        // General group should not leak into seo group
        self::assertArrayNotHasKey('site_name', $seoSettings);
    }
}

final class InMemorySettingsService implements SettingsServiceInterface
{
    /** @var array<string, mixed> keyed by "group:key:locale" */
    private array $settings = [];

    public function get(string $group, string $key, ?string $locale = null): mixed
    {
        // Try locale-specific first
        if ($locale !== null) {
            $localeKey = "{$group}:{$key}:{$locale}";

            if (array_key_exists($localeKey, $this->settings)) {
                return $this->settings[$localeKey];
            }
        }

        // Fall back to global
        $globalKey = "{$group}:{$key}:";

        return $this->settings[$globalKey] ?? null;
    }

    public function set(
        string $group,
        string $key,
        mixed $value,
        ?string $locale = null,
        ?string $reason = null,
    ): void {
        $storeKey = "{$group}:{$key}:{$locale}";
        $this->settings[$storeKey] = $value;
    }

    public function getGroup(string $group, ?string $locale = null): array
    {
        $result = [];
        $prefix = "{$group}:";

        foreach ($this->settings as $storeKey => $value) {
            if (!str_starts_with($storeKey, $prefix)) {
                continue;
            }

            // Parse "group:key:locale"
            $parts = explode(':', $storeKey, 3);
            $key = $parts[1];
            $settingLocale = $parts[2] !== '' ? $parts[2] : null;

            // Include global settings first
            if ($settingLocale === null) {
                $result[$key] = $value;
            }
        }

        // Override with locale-specific if requested
        if ($locale !== null) {
            foreach ($this->settings as $storeKey => $value) {
                if (!str_starts_with($storeKey, $prefix)) {
                    continue;
                }

                $parts = explode(':', $storeKey, 3);
                $key = $parts[1];
                $settingLocale = $parts[2] !== '' ? $parts[2] : null;

                if ($settingLocale === $locale) {
                    $result[$key] = $value;
                }
            }
        }

        return $result;
    }

    public function getAll(?string $locale = null): array
    {
        $result = [];

        foreach ($this->settings as $storeKey => $value) {
            $parts = explode(':', $storeKey, 3);
            $group = $parts[0];
            $key = $parts[1];
            $settingLocale = $parts[2] !== '' ? $parts[2] : null;

            if ($settingLocale === null) {
                $result[$group][$key] = $value;
            }
        }

        if ($locale !== null) {
            foreach ($this->settings as $storeKey => $value) {
                $parts = explode(':', $storeKey, 3);
                $group = $parts[0];
                $key = $parts[1];
                $settingLocale = $parts[2] !== '' ? $parts[2] : null;

                if ($settingLocale === $locale) {
                    $result[$group][$key] = $value;
                }
            }
        }

        return $result;
    }
}
