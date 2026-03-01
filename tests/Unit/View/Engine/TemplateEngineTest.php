<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Engine\TemplateCache;
use Pulsar\View\Engine\TemplateCompiler;
use Pulsar\View\Engine\TemplateEngine;
use Pulsar\View\Engine\TemplateEngineInterface;
use Pulsar\View\ViewConfig;
use Pulsar\View\ViewException;

use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(TemplateEngine::class)]
final class TemplateEngineTest extends TestCase
{
    private string $templateDir;

    private string $cacheDir;

    private TemplateEngine $engine;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_engine_test_' . uniqid();
        $this->templateDir = $base . DIRECTORY_SEPARATOR . 'views';
        $this->cacheDir = $base . DIRECTORY_SEPARATOR . 'cache';
        mkdir($this->templateDir, 0o755, true);
        mkdir($this->cacheDir, 0o755, true);

        $config = new ViewConfig(
            templatePaths: [$this->templateDir],
            cachePath: $this->cacheDir,
        );

        $cache = new TemplateCache($this->cacheDir);
        $compiler = new TemplateCompiler($config, $cache);
        $this->engine = new TemplateEngine($compiler);
    }

    protected function tearDown(): void
    {
        $this->removeRecursive(dirname($this->templateDir));
    }

    #[Test]
    public function implementsTemplateEngineInterface(): void
    {
        self::assertInstanceOf(TemplateEngineInterface::class, $this->engine);
    }

    #[Test]
    public function renderRendersStaticTemplate(): void
    {
        $this->writeTemplate('static', '<h1>Hello, World!</h1>');

        $result = $this->engine->render('static');

        self::assertSame('<h1>Hello, World!</h1>', $result);
    }

    #[Test]
    public function renderEscapesVariablesByDefault(): void
    {
        $this->writeTemplate('escape', '<p>{{ $name }}</p>');

        $result = $this->engine->render('escape', ['name' => '<script>alert("xss")</script>']);

        self::assertStringContainsString('&lt;script&gt;', $result);
        self::assertStringNotContainsString('<script>', $result);
    }

    #[Test]
    public function renderOutputsRawContentWithTripleBang(): void
    {
        $this->writeTemplate('raw', '<div>{!! $html !!}</div>');

        $result = $this->engine->render('raw', ['html' => '<strong>Bold</strong>']);

        self::assertSame('<div><strong>Bold</strong></div>', $result);
    }

    #[Test]
    public function renderPassesVariablesToTemplate(): void
    {
        $this->writeTemplate('vars', '<p>{{ $greeting }}, {{ $name }}!</p>');

        $result = $this->engine->render('vars', [
            'greeting' => 'Hello',
            'name' => 'World',
        ]);

        self::assertStringContainsString('Hello', $result);
        self::assertStringContainsString('World', $result);
    }

    #[Test]
    public function renderStripsComments(): void
    {
        $this->writeTemplate('comments', 'Before{{-- hidden --}}After');

        $result = $this->engine->render('comments');

        self::assertSame('BeforeAfter', $result);
    }

    #[Test]
    public function renderThrowsForMissingTemplate(): void
    {
        $this->expectException(ViewException::class);

        $this->engine->render('nonexistent');
    }

    #[Test]
    public function compileReturnsCompiledTemplate(): void
    {
        $this->writeTemplate('compilable', '<p>{{ $x }}</p>');

        $compiled = $this->engine->compile('compilable');

        self::assertFileExists($compiled->compiledPath);
        self::assertNotEmpty($compiled->sourceHash);
    }

    #[Test]
    public function existsReturnsTrueForExistingTemplate(): void
    {
        $this->writeTemplate('found', '<p>Here</p>');

        self::assertTrue($this->engine->exists('found'));
    }

    #[Test]
    public function existsReturnsFalseForMissingTemplate(): void
    {
        self::assertFalse($this->engine->exists('missing'));
    }

    #[Test]
    public function compilerReturnsCompilerInstance(): void
    {
        self::assertInstanceOf(TemplateCompiler::class, $this->engine->compiler());
    }

    #[Test]
    public function renderHandlesNestedDirectoryTemplates(): void
    {
        $this->writeTemplate('pages.about', '<h1>About {{ $company }}</h1>');

        $result = $this->engine->render('pages.about', ['company' => 'Pulsar']);

        self::assertStringContainsString('Pulsar', $result);
    }

    #[Test]
    public function renderEscapesSpecialHtmlEntities(): void
    {
        $this->writeTemplate('entities', '{{ $content }}');

        $result = $this->engine->render('entities', [
            'content' => '"quotes" & <tags> & \'apostrophes\'',
        ]);

        self::assertStringContainsString('&quot;quotes&quot;', $result);
        self::assertStringContainsString('&amp;', $result);
        self::assertStringContainsString('&lt;tags&gt;', $result);
        // ContextEscaper::html() uses ENT_HTML5 which produces &apos; (modern HTML5 entity)
        self::assertStringContainsString('&apos;apostrophes&apos;', $result);
    }

    private function writeTemplate(string $name, string $content): void
    {
        $relativePath = str_replace('.', DIRECTORY_SEPARATOR, $name) . '.pulse.php';
        $fullPath = $this->templateDir . DIRECTORY_SEPARATOR . $relativePath;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        file_put_contents($fullPath, $content);
    }

    private function removeRecursive(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fullPath)) {
                $this->removeRecursive($fullPath);
            } else {
                unlink($fullPath);
            }
        }

        rmdir($path);
    }
}
