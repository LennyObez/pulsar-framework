<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Engine\CompiledTemplate;
use Pulsar\View\Engine\TemplateCache;
use Pulsar\View\Engine\TemplateCompiler;
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

#[CoversClass(TemplateCompiler::class)]
final class TemplateCompilerTest extends TestCase
{
    private string $templateDir;

    private string $cacheDir;

    private TemplateCompiler $compiler;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_compiler_test_' . uniqid();
        $this->templateDir = $base . DIRECTORY_SEPARATOR . 'views';
        $this->cacheDir = $base . DIRECTORY_SEPARATOR . 'cache';
        mkdir($this->templateDir, 0o755, true);
        mkdir($this->cacheDir, 0o755, true);

        $config = new ViewConfig(
            templatePaths: [$this->templateDir],
            cachePath: $this->cacheDir,
        );

        $cache = new TemplateCache($this->cacheDir);
        $this->compiler = new TemplateCompiler($config, $cache);
    }

    protected function tearDown(): void
    {
        $this->removeRecursive(dirname($this->templateDir));
    }

    #[Test]
    public function compileSourceEscapesDoublebraceExpressions(): void
    {
        $result = $this->compiler->compileSource('Hello, {{ $name }}!');

        self::assertStringContainsString('htmlspecialchars', $result);
        self::assertStringContainsString('$name', $result);
        self::assertStringContainsString('ENT_QUOTES | ENT_SUBSTITUTE', $result);
        self::assertStringContainsString("'UTF-8'", $result);
    }

    #[Test]
    public function compileSourceHandlesRawEchoExpressions(): void
    {
        $result = $this->compiler->compileSource('Content: {!! $html !!}');

        self::assertStringContainsString('<?php echo $html; ?>', $result);
        self::assertStringNotContainsString('htmlspecialchars', $result);
    }

    #[Test]
    public function compileSourceStripsComments(): void
    {
        $result = $this->compiler->compileSource('Before{{-- this is a comment --}}After');

        self::assertSame('BeforeAfter', $result);
    }

    #[Test]
    public function compileSourceHandlesMultilineComments(): void
    {
        $source = "Before{{-- \nthis is\na multiline\ncomment --}}After";
        $result = $this->compiler->compileSource($source);

        self::assertSame('BeforeAfter', $result);
    }

    #[Test]
    public function compileSourceHandlesMultipleExpressions(): void
    {
        $source = '<h1>{{ $title }}</h1><p>{{ $body }}</p>';
        $result = $this->compiler->compileSource($source);

        self::assertStringContainsString('$title', $result);
        self::assertStringContainsString('$body', $result);
        self::assertSame(2, substr_count($result, 'htmlspecialchars'));
    }

    #[Test]
    public function compileSourcePreservesLiteralHtml(): void
    {
        $source = '<div class="container"><p>Static content</p></div>';
        $result = $this->compiler->compileSource($source);

        self::assertSame($source, $result);
    }

    #[Test]
    public function compileSourceHandlesExpressionWithSpaces(): void
    {
        $result = $this->compiler->compileSource('{{   $name   }}');

        self::assertStringContainsString('$name', $result);
        self::assertStringNotContainsString('   $name   ', $result);
    }

    #[Test]
    public function compileSourceHandlesComplexExpressions(): void
    {
        $result = $this->compiler->compileSource('{{ $user->getName() }}');

        self::assertStringContainsString('$user->getName()', $result);
    }

    #[Test]
    public function compileSourceHandlesTernaryExpressions(): void
    {
        $result = $this->compiler->compileSource('{{ $active ? "yes" : "no" }}');

        self::assertStringContainsString('$active ? "yes" : "no"', $result);
    }

    #[Test]
    public function compileSourceIsDeterministic(): void
    {
        $source = '<h1>{{ $title }}</h1><p>{!! $body !!}</p>{{-- comment --}}';

        $result1 = $this->compiler->compileSource($source);
        $result2 = $this->compiler->compileSource($source);

        self::assertSame($result1, $result2);
    }

    #[Test]
    public function compileResolvesAndCachesTemplate(): void
    {
        $this->writeTemplate('home', '<h1>{{ $title }}</h1>');

        $result = $this->compiler->compile('home');

        self::assertInstanceOf(CompiledTemplate::class, $result);
        self::assertFileExists($result->compiledPath);
    }

    #[Test]
    public function compileReturnsCachedVersionOnSecondCall(): void
    {
        $this->writeTemplate('cached', '<p>{{ $text }}</p>');

        $first = $this->compiler->compile('cached');
        $second = $this->compiler->compile('cached');

        self::assertSame($first->compiledPath, $second->compiledPath);
        self::assertSame($first->sourceHash, $second->sourceHash);
    }

    #[Test]
    public function compileRecompilesWhenSourceChanges(): void
    {
        $this->writeTemplate('changing', '<p>Version 1</p>');
        $first = $this->compiler->compile('changing');

        $this->writeTemplate('changing', '<p>Version 2</p>');
        $second = $this->compiler->compile('changing');

        self::assertNotSame($first->sourceHash, $second->sourceHash);
    }

    #[Test]
    public function compileThrowsForNonexistentTemplate(): void
    {
        $this->expectException(ViewException::class);

        $this->compiler->compile('nonexistent');
    }

    #[Test]
    public function resolveFindsTemplateInConfiguredPath(): void
    {
        $this->writeTemplate('layouts.main', '<html>{{ $content }}</html>');

        $path = $this->compiler->resolve('layouts.main');

        self::assertStringContainsString('layouts', $path);
        self::assertStringEndsWith('.pulsar.php', $path);
    }

    #[Test]
    public function resolveThrowsForMissingTemplate(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/not found/');

        (void) $this->compiler->resolve('missing.template');
    }

    #[Test]
    public function existsReturnsTrueForExistingTemplate(): void
    {
        $this->writeTemplate('exists', '<p>I exist</p>');

        self::assertTrue($this->compiler->exists('exists'));
    }

    #[Test]
    public function existsReturnsFalseForMissingTemplate(): void
    {
        self::assertFalse($this->compiler->exists('does.not.exist'));
    }

    #[Test]
    public function needsRecompilationReturnsTrueForNewTemplate(): void
    {
        $this->writeTemplate('fresh', '<p>New</p>');

        self::assertTrue($this->compiler->needsRecompilation('fresh'));
    }

    #[Test]
    public function needsRecompilationReturnsFalseForCachedTemplate(): void
    {
        $this->writeTemplate('compiled', '<p>Compiled</p>');

        $this->compiler->compile('compiled');

        self::assertFalse($this->compiler->needsRecompilation('compiled'));
    }

    #[Test]
    public function needsRecompilationReturnsTrueAfterSourceChange(): void
    {
        $this->writeTemplate('modified', '<p>V1</p>');
        $this->compiler->compile('modified');

        $this->writeTemplate('modified', '<p>V2</p>');

        self::assertTrue($this->compiler->needsRecompilation('modified'));
    }

    #[Test]
    public function registerDirectiveAddsCompiler(): void
    {
        self::assertFalse($this->compiler->hasDirective('custom'));

        $this->compiler->registerDirective('custom', static fn(string $expr): string => '<?php /* custom */ ?>');

        self::assertTrue($this->compiler->hasDirective('custom'));
    }

    #[Test]
    public function registeredDirectiveIsCompiled(): void
    {
        $this->compiler->registerDirective(
            'upper',
            static fn(string $expr): string => '<?php echo strtoupper(' . $expr . '); ?>',
        );

        $result = $this->compiler->compileSource('Hello @upper($name)');

        self::assertStringContainsString('strtoupper', $result);
        self::assertStringContainsString('$name', $result);
    }

    #[Test]
    public function unregisteredDirectivesArePassedThrough(): void
    {
        $result = $this->compiler->compileSource('Email: user@example.com');

        self::assertSame('Email: user@example.com', $result);
    }

    #[Test]
    public function resolveSearchesMultiplePaths(): void
    {
        $secondDir = dirname($this->templateDir) . DIRECTORY_SEPARATOR . 'views2';
        mkdir($secondDir, 0o755, true);

        $config = new ViewConfig(
            templatePaths: [$this->templateDir, $secondDir],
            cachePath: $this->cacheDir,
        );
        $cache = new TemplateCache($this->cacheDir);
        $compiler = new TemplateCompiler($config, $cache);

        $path = $secondDir . DIRECTORY_SEPARATOR . 'fallback.pulsar.php';
        file_put_contents($path, '<p>Fallback</p>');

        self::assertTrue($compiler->exists('fallback'));
        self::assertStringContainsString('views2', $compiler->resolve('fallback'));
    }

    #[Test]
    public function resolveFirstPathTakesPriority(): void
    {
        $secondDir = dirname($this->templateDir) . DIRECTORY_SEPARATOR . 'views2';
        mkdir($secondDir, 0o755, true);

        $config = new ViewConfig(
            templatePaths: [$this->templateDir, $secondDir],
            cachePath: $this->cacheDir,
        );
        $cache = new TemplateCache($this->cacheDir);
        $compiler = new TemplateCompiler($config, $cache);

        // Place template in both paths
        $this->writeTemplate('shared', '<p>Primary</p>');
        file_put_contents(
            $secondDir . DIRECTORY_SEPARATOR . 'shared.pulsar.php',
            '<p>Secondary</p>',
        );

        $resolved = $compiler->resolve('shared');

        self::assertStringContainsString((string) DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR, $resolved);
        self::assertStringNotContainsString('views2', $resolved);
    }

    #[Test]
    public function compileSourceHandlesMixedContent(): void
    {
        $source = <<<'TPL'
            <div>
                {{-- Page header --}}
                <h1>{{ $title }}</h1>
                <div class="content">
                    {!! $safeHtml !!}
                </div>
                <footer>{{ $copyright }}</footer>
            </div>
            TPL;

        $result = $this->compiler->compileSource($source);

        self::assertStringContainsString('<div>', $result);
        self::assertSame(2, substr_count($result, 'htmlspecialchars'));
        self::assertStringContainsString('echo $safeHtml;', $result);
        self::assertStringNotContainsString('Page header', $result);
    }

    private function writeTemplate(string $name, string $content): void
    {
        $relativePath = str_replace('.', DIRECTORY_SEPARATOR, $name) . '.pulsar.php';
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
