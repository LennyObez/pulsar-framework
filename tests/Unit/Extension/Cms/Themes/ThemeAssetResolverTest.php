<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Themes;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Cms\Config\ThemesConfig;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Themes\ThemeAssetResolver;
use Pulsar\Extension\Cms\Themes\InstalledTheme;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;

use function bin2hex;
use function file_put_contents;
use function hash_file;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(ThemeAssetResolver::class)]
final class ThemeAssetResolverTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pulsar_theme_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpDir);
    }

    // -- Path traversal protection --------------------------------------------

    #[Test]
    public function test_path_traversal_in_template_name_rejected(): void
    {
        $theme = $this->createInstalledTheme(storagePath: $this->tmpDir);
        $repo = $this->createRepository(byId: $theme, active: $theme);
        $resolver = new ThemeAssetResolver($repo, new ThemesConfig(), new NullLogger());

        $this->expectException(CmsException::class);
        $resolver->resolveTemplate('../../../etc/passwd', $theme->id);
    }

    #[Test]
    public function test_path_traversal_with_double_dots_in_asset_rejected(): void
    {
        $theme = $this->createInstalledTheme(storagePath: $this->tmpDir);
        $repo = $this->createRepository(byId: $theme, active: $theme);
        $resolver = new ThemeAssetResolver($repo, new ThemesConfig(), new NullLogger());

        $this->expectException(CmsException::class);
        $resolver->resolve('../../secret.txt', $theme->id);
    }

    #[Test]
    public function test_absolute_path_in_template_rejected(): void
    {
        $theme = $this->createInstalledTheme(storagePath: $this->tmpDir);
        $repo = $this->createRepository(byId: $theme, active: $theme);
        $resolver = new ThemeAssetResolver($repo, new ThemesConfig(), new NullLogger());

        $this->expectException(CmsException::class);
        $resolver->resolveTemplate('/etc/passwd', $theme->id);
    }

    #[Test]
    public function test_null_byte_in_template_name_rejected(): void
    {
        $theme = $this->createInstalledTheme(storagePath: $this->tmpDir);
        $repo = $this->createRepository(byId: $theme, active: $theme);
        $resolver = new ThemeAssetResolver($repo, new ThemesConfig(), new NullLogger());

        $this->expectException(CmsException::class);
        $resolver->resolveTemplate("template\0.php", $theme->id);
    }

    // -- Template resolution --------------------------------------------------

    #[Test]
    public function test_resolve_template_within_theme_directory(): void
    {
        $templatesDir = $this->tmpDir . '/templates';
        mkdir($templatesDir, 0o777, true);
        file_put_contents($templatesDir . '/article.pulsar.php', '<?php // template');

        $theme = $this->createInstalledTheme(storagePath: $this->tmpDir);
        $repo = $this->createRepository(byId: $theme, active: $theme);
        $resolver = new ThemeAssetResolver($repo, new ThemesConfig(), new NullLogger());

        $result = $resolver->resolveTemplate('article', $theme->id);

        self::assertSame($templatesDir . '/article.pulsar.php', $result);
    }

    #[Test]
    public function test_missing_template_throws(): void
    {
        $templatesDir = $this->tmpDir . '/templates';
        mkdir($templatesDir, 0o777, true);

        $theme = $this->createInstalledTheme(storagePath: $this->tmpDir);
        $repo = $this->createRepository(byId: $theme, active: $theme);
        $resolver = new ThemeAssetResolver($repo, new ThemesConfig(), new NullLogger());

        $this->expectException(CmsException::class);
        $resolver->resolveTemplate('nonexistent', $theme->id);
    }

    // -- Theme resolution fallback --------------------------------------------

    #[Test]
    public function test_resolve_uses_active_theme_when_no_id_provided(): void
    {
        $templatesDir = $this->tmpDir . '/templates';
        mkdir($templatesDir, 0o777, true);
        file_put_contents($templatesDir . '/page.pulsar.php', '<?php // page');

        $theme = $this->createInstalledTheme(storagePath: $this->tmpDir);
        $repo = $this->createRepository(byId: null, active: $theme);
        $resolver = new ThemeAssetResolver($repo, new ThemesConfig(), new NullLogger());

        $result = $resolver->resolveTemplate('page');

        self::assertSame($templatesDir . '/page.pulsar.php', $result);
    }

    #[Test]
    public function test_throws_when_no_active_theme_and_no_id(): void
    {
        $repo = $this->createRepository(byId: null, active: null);
        $resolver = new ThemeAssetResolver($repo, new ThemesConfig(), new NullLogger());

        $this->expectException(CmsException::class);
        $resolver->resolveTemplate('page');
    }

    #[Test]
    public function test_throws_when_theme_id_not_found(): void
    {
        $repo = $this->createRepository(byId: null, active: null);
        $resolver = new ThemeAssetResolver($repo, new ThemesConfig(), new NullLogger());

        $this->expectException(CmsException::class);
        $resolver->resolveTemplate('page', 'nonexistent-id');
    }

    // -- Integrity verification -----------------------------------------------

    #[Test]
    public function test_integrity_check_passes_when_hash_matches(): void
    {
        // Create a theme.json file in the storage path
        $manifestPath = $this->tmpDir . '/theme.json';
        file_put_contents($manifestPath, '{"slug":"test","version":"1.0.0"}');
        $hash = hash_file('sha256', $manifestPath);
        self::assertIsString($hash, 'hash_file must not fail');

        $theme = $this->createInstalledTheme(
            storagePath: $this->tmpDir,
            manifestHash: $hash,
        );
        $repo = $this->createRepository(byId: $theme);
        $resolver = new ThemeAssetResolver($repo, new ThemesConfig(), new NullLogger());

        $result = $resolver->verifyIntegrity($theme->id);

        self::assertTrue($result->hashValid);
        self::assertTrue($result->signatureValid);
    }

    #[Test]
    public function test_integrity_check_fails_when_hash_mismatch(): void
    {
        $manifestPath = $this->tmpDir . '/theme.json';
        file_put_contents($manifestPath, '{"slug":"test","version":"1.0.0"}');

        $theme = $this->createInstalledTheme(
            storagePath: $this->tmpDir,
            manifestHash: 'deadbeef_wrong_hash_value',
        );
        $repo = $this->createRepository(byId: $theme);
        $resolver = new ThemeAssetResolver($repo, new ThemesConfig(), new NullLogger());

        $result = $resolver->verifyIntegrity($theme->id);

        self::assertFalse($result->hashValid);
        self::assertNotNull($result->error);
    }

    #[Test]
    public function test_integrity_check_fails_when_manifest_missing(): void
    {
        $theme = $this->createInstalledTheme(
            storagePath: $this->tmpDir . '/empty',
            manifestHash: 'some-hash',
        );
        $repo = $this->createRepository(byId: $theme);
        $resolver = new ThemeAssetResolver($repo, new ThemesConfig(), new NullLogger());

        $result = $resolver->verifyIntegrity($theme->id);

        self::assertFalse($result->hashValid);
    }

    #[Test]
    public function test_integrity_check_fails_when_theme_not_found(): void
    {
        $repo = $this->createRepository(byId: null);
        $resolver = new ThemeAssetResolver($repo, new ThemesConfig(), new NullLogger());

        $result = $resolver->verifyIntegrity('nonexistent-id');

        self::assertFalse($result->hashValid);
    }

    // -- Helpers --------------------------------------------------------------

    private function createInstalledTheme(
        string $storagePath = '/tmp/theme',
        string $manifestHash = 'abc123',
        string $id = 'theme-001',
    ): InstalledTheme {
        $now = new DateTimeImmutable();

        return new InstalledTheme(
            id: $id,
            tenantId: null,
            slug: 'test-theme',
            displayName: 'Test Theme',
            version: '1.0.0',
            description: 'A test theme',
            authorName: 'Author',
            authorUrl: null,
            license: 'MIT',
            manifestHash: $manifestHash,
            packageHash: 'package-hash',
            provenanceVerified: true,
            signatureVerified: true,
            isActive: true,
            storagePath: $storagePath,
            installedAt: $now,
            installedBy: 'user-001',
            activatedAt: $now,
            activatedBy: 'user-001',
            deactivatedAt: null,
            deletedAt: null,
        );
    }

    private function createRepository(
        ?InstalledTheme $byId = null,
        ?InstalledTheme $active = null,
    ): ThemeRepositoryInterface {
        return new class ($byId, $active) implements ThemeRepositoryInterface {
            public function __construct(
                private readonly ?InstalledTheme $byId,
                private readonly ?InstalledTheme $active = null,
            ) {}

            public function findById(string $id): ?InstalledTheme
            {
                return $this->byId;
            }

            public function findBySlug(string $slug, ?string $tenantId = null): ?InstalledTheme
            {
                return null;
            }

            public function findActive(?string $tenantId = null): ?InstalledTheme
            {
                return $this->active;
            }

            public function findAll(?string $tenantId = null): array
            {
                return [];
            }

            public function save(InstalledTheme $theme): void {}

            public function delete(string $themeId): void {}
        };
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
