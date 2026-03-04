<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\PermissionsPolicyConfig;

#[CoversClass(PermissionsPolicyConfig::class)]
final class PermissionsPolicyConfigTest extends TestCase
{
    #[Test]
    public function defaults_deny_sensitive_features(): void
    {
        $config = new PermissionsPolicyConfig();
        $value = $config->toHeaderValue();

        self::assertStringContainsString('camera=()', $value);
        self::assertStringContainsString('microphone=()', $value);
        self::assertStringContainsString('geolocation=()', $value);
        self::assertStringContainsString('payment=()', $value);
        self::assertStringContainsString('usb=()', $value);
    }

    #[Test]
    public function defaults_allow_self_for_media_features(): void
    {
        $config = new PermissionsPolicyConfig();
        $value = $config->toHeaderValue();

        self::assertStringContainsString('autoplay=(self)', $value);
        self::assertStringContainsString('fullscreen=(self)', $value);
    }

    #[Test]
    public function custom_values_override_defaults(): void
    {
        $config = new PermissionsPolicyConfig(
            camera: '(self)',
            microphone: '(self "https://meet.example.com")',
        );
        $value = $config->toHeaderValue();

        self::assertStringContainsString('camera=(self)', $value);
        self::assertStringContainsString('microphone=(self "https://meet.example.com")', $value);
    }

    #[Test]
    public function additional_features_included(): void
    {
        $config = new PermissionsPolicyConfig(
            additional: ['bluetooth' => '()', 'serial' => '()'],
        );
        $value = $config->toHeaderValue();

        self::assertStringContainsString('bluetooth=()', $value);
        self::assertStringContainsString('serial=()', $value);
    }

    #[Test]
    public function empty_values_excluded(): void
    {
        $config = new PermissionsPolicyConfig(camera: '');
        $value = $config->toHeaderValue();

        self::assertStringNotContainsString('camera', $value);
    }

    #[Test]
    public function from_array_parses_config(): void
    {
        $config = PermissionsPolicyConfig::fromArray([
            'camera' => '(self)',
            'geolocation' => '(self "https://maps.example.com")',
            'additional' => ['bluetooth' => '()'],
        ]);

        self::assertSame('(self)', $config->camera);
        self::assertSame('(self "https://maps.example.com")', $config->geolocation);
        self::assertSame(['bluetooth' => '()'], $config->additional);
    }

    #[Test]
    public function from_array_uses_defaults_for_missing_keys(): void
    {
        $config = PermissionsPolicyConfig::fromArray([]);

        self::assertSame('()', $config->camera);
        self::assertSame('()', $config->microphone);
        self::assertSame('(self)', $config->autoplay);
    }
}
