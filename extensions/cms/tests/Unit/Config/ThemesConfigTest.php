<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\ThemesConfig;

#[CoversClass(ThemesConfig::class)]
final class ThemesConfigTest extends TestCase
{
    #[Test]
    public function defaultsRequireSignedThemesAndIntegrityCheck(): void
    {
        $config = new ThemesConfig();

        self::assertSame('storage/cms/themes', $config->storagePath);
        self::assertSame('copy', $config->assetDeployMode);
        self::assertTrue($config->requireSignedThemes);
        self::assertSame([], $config->trustedPublicKeys);
        self::assertTrue($config->integrityCheckOnBoot);
        self::assertSame(52_428_800, $config->maxArchiveSize);
        self::assertSame(10_000, $config->maxFileCount);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = ThemesConfig::fromArray([
            'storage_path' => '/var/themes',
            'asset_deploy_mode' => 'symlink',
            'require_signed_themes' => false,
            'trusted_public_keys' => ['key-abc', 'key-def'],
            'integrity_check_on_boot' => false,
            'max_archive_size' => 104_857_600,
            'max_file_count' => 20_000,
        ]);

        self::assertSame('/var/themes', $config->storagePath);
        self::assertSame('symlink', $config->assetDeployMode);
        self::assertFalse($config->requireSignedThemes);
        self::assertSame(['key-abc', 'key-def'], $config->trustedPublicKeys);
        self::assertFalse($config->integrityCheckOnBoot);
        self::assertSame(104_857_600, $config->maxArchiveSize);
        self::assertSame(20_000, $config->maxFileCount);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = ThemesConfig::fromArray([]);

        self::assertTrue($config->requireSignedThemes);
        self::assertTrue($config->integrityCheckOnBoot);
        self::assertSame([], $config->trustedPublicKeys);
        self::assertSame(52_428_800, $config->maxArchiveSize);
    }

    #[Test]
    public function fromArrayIgnoresWrongTypes(): void
    {
        $config = ThemesConfig::fromArray([
            'storage_path' => 42,
            'asset_deploy_mode' => false,
            'require_signed_themes' => 'yes',
            'trusted_public_keys' => 'not-an-array',
            'integrity_check_on_boot' => 1,
            'max_archive_size' => '50MB',
            'max_file_count' => 10.5,
        ]);

        self::assertSame('storage/cms/themes', $config->storagePath);
        self::assertSame('copy', $config->assetDeployMode);
        self::assertTrue($config->requireSignedThemes);
        self::assertSame([], $config->trustedPublicKeys);
        self::assertTrue($config->integrityCheckOnBoot);
        self::assertSame(52_428_800, $config->maxArchiveSize);
        self::assertSame(10_000, $config->maxFileCount);
    }

    #[Test]
    public function fromArrayConvertsNonStringKeysToEmptyString(): void
    {
        $config = ThemesConfig::fromArray([
            'trusted_public_keys' => ['key1', 42, true, 'key2'],
        ]);

        self::assertSame(['key1', '', '', 'key2'], $config->trustedPublicKeys);
    }
}
