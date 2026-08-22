<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Settings;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Settings\SiteSetting;

#[CoversClass(SiteSetting::class)]
final class SiteSettingTest extends TestCase
{
    #[Test]
    public function constructor(): void
    {
        $now = new DateTimeImmutable('2025-03-07T10:00:00+00:00');

        $setting = new SiteSetting(
            id: 'setting-01',
            tenantId: 'tenant-01',
            group: 'general',
            key: 'site_name',
            locale: null,
            value: '"Pulsar CMS"',
            valueType: 'string',
            updatedAt: $now,
            updatedBy: 'user-admin',
        );

        self::assertSame('setting-01', $setting->id);
        self::assertSame('tenant-01', $setting->tenantId);
        self::assertSame('general', $setting->group);
        self::assertSame('site_name', $setting->key);
        self::assertNull($setting->locale);
        self::assertSame('"Pulsar CMS"', $setting->value);
        self::assertSame('string', $setting->valueType);
        self::assertSame('user-admin', $setting->updatedBy);
    }

    #[Test]
    public function constructorWithLocale(): void
    {
        $setting = new SiteSetting(
            id: 'setting-02',
            tenantId: null,
            group: 'seo',
            key: 'title_suffix',
            locale: 'fr',
            value: '" | Mon Site"',
            valueType: 'string',
            updatedAt: new DateTimeImmutable(),
            updatedBy: 'user-editor',
        );

        self::assertSame('fr', $setting->locale);
        self::assertNull($setting->tenantId);
    }

    #[Test]
    public function encryptedValueType(): void
    {
        $setting = new SiteSetting(
            id: 'setting-03',
            tenantId: 'tenant-01',
            group: 'security',
            key: 'api_secret',
            locale: null,
            value: 'encrypted:base64data',
            valueType: 'encrypted',
            updatedAt: new DateTimeImmutable(),
            updatedBy: 'user-admin',
        );

        self::assertSame('encrypted', $setting->valueType);
    }
}
