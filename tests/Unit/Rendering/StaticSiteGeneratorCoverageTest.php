<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Rendering\PageRendererInterface;
use Pulsar\Rendering\StaticSiteConfig;
use Pulsar\Rendering\StaticSiteGenerator;

/**
 * Coverage tests for StaticSiteGenerator::writeToDisk() and pathToFile().
 */
#[CoversClass(StaticSiteGenerator::class)]
final class StaticSiteGeneratorCoverageTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'pulsar-ssg-test-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // Use platform-appropriate recursive delete via shell for test cleanup.
        // The path is constructed from sys_get_temp_dir() + random bytes in setUp(),
        // never from user input.
        if (is_dir($this->tempDir)) {
            $safeDir = escapeshellarg($this->tempDir);
            if (DIRECTORY_SEPARATOR === '\\') {
                exec("rmdir /s /q {$safeDir} 2>NUL");
            } else {
                exec("rm -rf {$safeDir} 2>/dev/null");
            }
        }
    }

    #[Test]
    public function writeToDiskCreatesIndexHtmlForRootPath(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<h1>Home</h1>');

        $config = new StaticSiteConfig(
            outputDir: $this->tempDir,
            excludePatterns: [],
        );
        $ssg = new StaticSiteGenerator($config, $renderer);
        $ssg->renderAll(['/']);

        $written = $ssg->writeToDisk();

        self::assertSame(1, $written);
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'index.html');
        self::assertSame('<h1>Home</h1>', file_get_contents($this->tempDir . DIRECTORY_SEPARATOR . 'index.html'));
    }

    #[Test]
    public function writeToDiskCreatesNestedDirectories(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<p>Nested</p>');

        $config = new StaticSiteConfig(
            outputDir: $this->tempDir,
            excludePatterns: [],
        );
        $ssg = new StaticSiteGenerator($config, $renderer);
        $ssg->renderAll(['/docs/getting-started']);

        $written = $ssg->writeToDisk();

        self::assertSame(1, $written);
        $expectedPath = $this->tempDir . DIRECTORY_SEPARATOR
            . 'docs' . DIRECTORY_SEPARATOR
            . 'getting-started' . DIRECTORY_SEPARATOR
            . 'index.html';
        self::assertFileExists($expectedPath);
    }

    #[Test]
    public function writeToDiskHandlesMultiplePages(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturnCallback(
            fn(string $path) => "<p>{$path}</p>",
        );

        $config = new StaticSiteConfig(
            outputDir: $this->tempDir,
            excludePatterns: [],
        );
        $ssg = new StaticSiteGenerator($config, $renderer);
        $ssg->renderAll(['/', '/about', '/blog/post-1']);

        $written = $ssg->writeToDisk();

        self::assertSame(3, $written);
    }

    #[Test]
    public function writeToDiskReturnsZeroWhenNoPages(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $config = new StaticSiteConfig(outputDir: $this->tempDir);
        $ssg = new StaticSiteGenerator($config, $renderer);

        $written = $ssg->writeToDisk();

        self::assertSame(0, $written);
    }

    #[Test]
    public function writeToDiskCreatesIndexHtmlForEmptyPath(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<p>empty</p>');

        $config = new StaticSiteConfig(
            outputDir: $this->tempDir,
            excludePatterns: [],
        );
        $ssg = new StaticSiteGenerator($config, $renderer);
        $ssg->renderRoute('/');

        $ssg->writeToDisk();

        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'index.html');
    }

    #[Test]
    public function pagesReturnsEmptyAfterRenderAllReset(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<p>ok</p>');

        $config = new StaticSiteConfig(excludePatterns: []);
        $ssg = new StaticSiteGenerator($config, $renderer);

        $ssg->renderAll(['/a', '/b']);
        self::assertCount(2, $ssg->pages());

        // renderAll resets state
        $ssg->renderAll([]);
        self::assertCount(0, $ssg->pages());
    }

    #[Test]
    public function renderRouteAccumulatesWithoutReset(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<p>ok</p>');

        $config = new StaticSiteConfig();
        $ssg = new StaticSiteGenerator($config, $renderer);

        $ssg->renderRoute('/a');
        $ssg->renderRoute('/b');

        self::assertCount(2, $ssg->pages());
    }

    #[Test]
    public function writeToDiskRefusesParentDirectoryTraversal(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<p>pwned</p>');

        $config = new StaticSiteConfig(
            outputDir: $this->tempDir . DIRECTORY_SEPARATOR . 'output',
            excludePatterns: [],
        );
        $ssg = new StaticSiteGenerator($config, $renderer);
        $ssg->renderRoute('/../escape');

        $written = $ssg->writeToDisk();

        self::assertSame(0, $written);
        self::assertCount(1, $ssg->errors());
        self::assertStringContainsString('path traversal', $ssg->errors()[0]);
        // The traversal target one level above the output dir must not exist.
        self::assertFileDoesNotExist(
            $this->tempDir . DIRECTORY_SEPARATOR . 'escape' . DIRECTORY_SEPARATOR . 'index.html',
        );
    }

    #[Test]
    public function writeToDiskRecordsDirectoryCreationFailure(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<p>blocked</p>');

        // Place a regular file where writeToDisk() must create a directory,
        // forcing mkdir() to fail for the nested page.
        $blocker = $this->tempDir . DIRECTORY_SEPARATOR . 'blocked';
        file_put_contents($blocker, 'not a directory');

        $config = new StaticSiteConfig(
            outputDir: $this->tempDir,
            excludePatterns: [],
        );
        $ssg = new StaticSiteGenerator($config, $renderer);
        $ssg->renderRoute('/blocked/page');

        // Suppress the expected mkdir() warning emitted by the source code when
        // directory creation legitimately fails; the failure is asserted via
        // the recorded error below.
        set_error_handler(static fn(): bool => true);

        try {
            $written = $ssg->writeToDisk();
        } finally {
            restore_error_handler();
        }

        self::assertSame(0, $written);
        self::assertCount(1, $ssg->errors());
        self::assertStringContainsString('Failed to create directory', $ssg->errors()[0]);
    }
}
