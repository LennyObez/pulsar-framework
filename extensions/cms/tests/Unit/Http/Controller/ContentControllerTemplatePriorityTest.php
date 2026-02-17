<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Http\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Http\Controller\ContentController;
use Pulsar\Extension\Cms\Themes\InstalledTheme;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;
use ReflectionClass;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function str_starts_with;
use function sys_get_temp_dir;

/**
 * Verifies that ContentController template resolution follows the priority:
 *   1. Project views (resources/views/{template}.pulse.php)
 *   2. Active theme templates ({theme_storage}/templates/{template}.pulse.php)
 *   3. CMS default templates (cms::public.pages.{template})
 *
 * Tests the private resolveTemplateName() method via reflection since it is
 * a pure function with no side effects beyond filesystem checks.
 */
#[CoversClass(ContentController::class)]
final class ContentControllerTemplatePriorityTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            $this->removeTempFile($path);
        }

        $dirs = array_reverse($this->tempDirs);

        foreach ($dirs as $path) {
            $this->removeTempDir($path);
        }

        $this->tempFiles = [];
        $this->tempDirs = [];
    }

    #[Test]
    public function fullyQualifiedTemplateReturnedAsIs(): void
    {
        $result = $this->callResolve('cms::public.pages.landing', '', null);

        self::assertSame('cms::public.pages.landing', $result);
    }

    #[Test]
    public function projectTemplateOverridesThenAndCmsDefaults(): void
    {
        $projectDir = $this->createTempDir();
        $templateFile = $projectDir . '/article.pulse.php';
        file_put_contents($templateFile, '<?php echo "project template"; ?>');
        $this->tempFiles[] = $templateFile;

        $result = $this->callResolve('article', $projectDir, null);

        self::assertSame($templateFile, $result);
    }

    #[Test]
    public function activeThemeTemplateOverridesCmsDefaults(): void
    {
        $themeDir = $this->createTempDir();
        $templatesDir = $themeDir . '/templates';
        mkdir($templatesDir, 0o755, true);
        $this->tempDirs[] = $templatesDir;

        $templateFile = $templatesDir . '/article.pulse.php';
        file_put_contents($templateFile, '<?php echo "theme template"; ?>');
        $this->tempFiles[] = $templateFile;

        $theme = $this->buildInstalledTheme($themeDir);
        $themeRepo = $this->createStub(ThemeRepositoryInterface::class);
        $themeRepo->method('findActive')->willReturn($theme);

        // No project views path, so project override is skipped
        $result = $this->callResolve('article', '', $themeRepo);

        self::assertSame($templateFile, $result);
    }

    #[Test]
    public function projectTemplateOverridesActiveTheme(): void
    {
        // Set up both project and theme templates
        $projectDir = $this->createTempDir();
        $projectFile = $projectDir . '/page.pulse.php';
        file_put_contents($projectFile, '<?php echo "project"; ?>');
        $this->tempFiles[] = $projectFile;

        $themeDir = $this->createTempDir();
        $templatesDir = $themeDir . '/templates';
        mkdir($templatesDir, 0o755, true);
        $this->tempDirs[] = $templatesDir;
        $themeFile = $templatesDir . '/page.pulse.php';
        file_put_contents($themeFile, '<?php echo "theme"; ?>');
        $this->tempFiles[] = $themeFile;

        $theme = $this->buildInstalledTheme($themeDir);
        $themeRepo = $this->createStub(ThemeRepositoryInterface::class);
        $themeRepo->method('findActive')->willReturn($theme);

        // Project should win over theme
        $result = $this->callResolve('page', $projectDir, $themeRepo);

        self::assertSame($projectFile, $result);
    }

    #[Test]
    public function fallsThroughToCmsDefaultsWhenNoOverrides(): void
    {
        $themeRepo = $this->createStub(ThemeRepositoryInterface::class);
        $themeRepo->method('findActive')->willReturn(null);

        $result = $this->callResolve('article', '', $themeRepo);

        self::assertSame('cms::public.pages.article', $result);
    }

    #[Test]
    public function cmsDefaultMapIncludesPageTemplate(): void
    {
        $result = $this->callResolve('page', '', null);

        self::assertSame('cms::public.pages.page', $result);
    }

    #[Test]
    public function cmsDefaultMapFallsBackToGenericPrefix(): void
    {
        $result = $this->callResolve('gallery', '', null);

        self::assertSame('cms::public.pages.gallery', $result);
    }

    /**
     * Invoke the private resolveTemplateName method via reflection.
     */
    private function callResolve(
        string $template,
        string $projectViewsPath,
        ?ThemeRepositoryInterface $themeRepo,
    ): string {
        $controller = $this->buildController($projectViewsPath, $themeRepo);

        $ref = new ReflectionClass($controller);
        $method = $ref->getMethod('resolveTemplateName');

        /** @var string $result */
        $result = $method->invoke($controller, $template);

        return $result;
    }

    private function buildController(
        string $projectViewsPath,
        ?ThemeRepositoryInterface $themeRepo,
    ): ContentController {
        // Build a minimal controller using newInstanceWithoutConstructor, then
        // set the properties we need via reflection. The resolveTemplateName
        // method only reads projectViewsPath and themeRepository.
        $ref = new ReflectionClass(ContentController::class);

        /** @var ContentController $controller */
        $controller = $ref->newInstanceWithoutConstructor();

        // Use the clone-with approach is not possible on a reflected instance
        // since it is readonly. Instead, set private properties via reflection.
        $projectProp = $ref->getProperty('projectViewsPath');
        $projectProp->setValue($controller, $projectViewsPath);

        $themeProp = $ref->getProperty('themeRepository');
        $themeProp->setValue($controller, $themeRepo);

        return $controller;
    }

    private function buildInstalledTheme(string $storagePath): InstalledTheme
    {
        $now = new DateTimeImmutable();

        return new InstalledTheme(
            id: 'theme-' . bin2hex(random_bytes(4)),
            tenantId: null,
            slug: 'test-theme',
            displayName: 'Test Theme',
            version: '1.0.0',
            description: null,
            authorName: null,
            authorUrl: null,
            license: null,
            manifestHash: 'hash',
            packageHash: 'hash',
            provenanceVerified: false,
            signatureVerified: false,
            isActive: true,
            storagePath: $storagePath,
            installedAt: $now,
            installedBy: 'test',
            activatedAt: $now,
            activatedBy: 'test',
            deactivatedAt: null,
            deletedAt: null,
        );
    }

    private function createTempDir(): string
    {
        $base = sys_get_temp_dir() . '/pulsar_test_' . bin2hex(random_bytes(8));
        mkdir($base, 0o755, true);
        $this->tempDirs[] = $base;

        return $base;
    }

    /**
     * Safely remove a temp file created by this test.
     */
    private function removeTempFile(string $path): void
    {
        $tempDir = sys_get_temp_dir();

        if (str_starts_with($path, $tempDir) && is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Safely remove a temp directory created by this test.
     */
    private function removeTempDir(string $path): void
    {
        $tempDir = sys_get_temp_dir();

        if (str_starts_with($path, $tempDir) && is_dir($path)) {
            rmdir($path);
        }
    }
}
