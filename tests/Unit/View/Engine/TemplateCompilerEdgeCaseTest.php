<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
use function sys_get_temp_dir;
use function uniqid;

use const DIRECTORY_SEPARATOR;

#[CoversClass(TemplateCompiler::class)]
final class TemplateCompilerEdgeCaseTest extends TestCase
{
    private string $templateDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_compiler_edge_' . uniqid();
        $this->templateDir = $base . DIRECTORY_SEPARATOR . 'views';
        $this->cacheDir = $base . DIRECTORY_SEPARATOR . 'cache';
        mkdir($this->templateDir, 0o755, true);
        mkdir($this->cacheDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeRecursive(dirname($this->templateDir));
    }

    #[Test]
    public function compileSourceHandlesEmptyInput(): void
    {
        $compiler = $this->createCompiler();
        $result = $compiler->compileSource('');

        self::assertSame('', $result);
    }

    #[Test]
    public function compileSourceCompilesMultilineComments(): void
    {
        $compiler = $this->createCompiler();
        $result = $compiler->compileSource("Before{{-- \nmultiline\ncomment --}}After");

        self::assertSame('BeforeAfter', $result);
    }

    #[Test]
    public function compileSourceCompilesMultipleEchoExpressions(): void
    {
        $compiler = $this->createCompiler();
        $result = $compiler->compileSource('{{ $a }} and {{ $b }}');

        self::assertSame(2, substr_count($result, 'ContextEscaper::html'));
    }

    #[Test]
    public function directiveWithNestedParensCompilesCorrectly(): void
    {
        $compiler = $this->createCompiler();
        $compiler->registerDirective('foreach', static fn(string $expr): string => "<?php foreach ({$expr}): ?>");

        $result = $compiler->compileSource('@foreach (($items ?? []) as $item)');

        self::assertSame('<?php foreach (($items ?? []) as $item): ?>', $result);
    }

    #[Test]
    public function unregisteredDirectivePassesThroughVerbatim(): void
    {
        $compiler = $this->createCompiler();

        $result = $compiler->compileSource('user@example.com');

        self::assertSame('user@example.com', $result);
    }

    #[Test]
    public function atSignWithoutAlphabeticFollowerPassesThrough(): void
    {
        $compiler = $this->createCompiler();
        $compiler->registerDirective('test', static fn(string $e): string => 'COMPILED');

        $result = $compiler->compileSource('email@123');

        self::assertSame('email@123', $result);
    }

    #[Test]
    public function resolveHandlesNamespacedTemplates(): void
    {
        $this->writeTemplate('admin.dashboard', '<h1>Dashboard</h1>');
        $compiler = $this->createCompiler();

        $path = $compiler->resolve('cms::admin.dashboard');

        self::assertFileExists($path);
    }

    #[Test]
    public function compileWithSandboxModeCreatesSandboxCompiler(): void
    {
        $config = new ViewConfig(
            templatePaths: [$this->templateDir],
            cachePath: $this->cacheDir,
            sandboxMode: true,
        );
        $cache = new TemplateCache($this->cacheDir);
        $compiler = new TemplateCompiler($config, $cache);

        $this->writeTemplate('safe', '<p>{{ $x }}</p>');

        $compiled = $compiler->compile('safe');

        self::assertInstanceOf(CompiledTemplate::class, $compiled);
    }

    #[Test]
    public function compileWithSandboxRejectsDeniedFunctions(): void
    {
        $config = new ViewConfig(
            templatePaths: [$this->templateDir],
            cachePath: $this->cacheDir,
            sandboxMode: true,
        );
        $cache = new TemplateCache($this->cacheDir);
        $compiler = new TemplateCompiler($config, $cache);

        $this->writeTemplate('denied', '<?php exec("ls"); ?>');

        $this->expectException(ViewException::class);

        $compiler->compile('denied');
    }

    #[Test]
    public function directiveWithWhitespaceBetweenNameAndParen(): void
    {
        $compiler = $this->createCompiler();
        $compiler->registerDirective('if', static fn(string $expr): string => "<?php if ({$expr}): ?>");

        $result = $compiler->compileSource('@if  ($x > 0)');

        self::assertStringContainsString('<?php if ($x > 0): ?>', $result);
    }

    #[Test]
    public function compileSourceWithMixedDirectivesCommentsAndEchos(): void
    {
        $compiler = $this->createCompiler();
        $compiler->registerDirective('if', static fn(string $expr): string => "<?php if ({$expr}): ?>");
        $compiler->registerDirective('endif', static fn(string $e): string => '<?php endif; ?>');

        $source = '{{-- greeting --}}@if($show){{ $name }}@endif';
        $result = $compiler->compileSource($source);

        self::assertStringNotContainsString('{{--', $result);
        self::assertStringContainsString('<?php if ($show): ?>', $result);
        self::assertStringContainsString('ContextEscaper::html', $result);
        self::assertStringContainsString('<?php endif; ?>', $result);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function echoExpressionProvider(): array
    {
        return [
            'simple variable' => ['{{ $name }}', '$name'],
            'method call' => ['{{ $user->name() }}', '$user->name()'],
            'ternary' => ['{{ $x ? "yes" : "no" }}', '$x ? "yes" : "no"'],
            'concatenation' => ['{{ $a . $b }}', '$a . $b'],
        ];
    }

    #[Test]
    #[DataProvider('echoExpressionProvider')]
    public function compileSourceCompilesVariousEchoExpressions(string $input, string $expectedExpr): void
    {
        $compiler = $this->createCompiler();
        $result = $compiler->compileSource($input);

        self::assertStringContainsString($expectedExpr, $result);
        self::assertStringContainsString('ContextEscaper::html', $result);
    }

    #[Test]
    public function needsRecompilationReturnsTrueForUncachedTemplate(): void
    {
        $this->writeTemplate('uncached', '<p>new</p>');
        $compiler = $this->createCompiler();

        self::assertTrue($compiler->needsRecompilation('uncached'));
    }

    #[Test]
    public function needsRecompilationReturnsFalseForCachedTemplate(): void
    {
        $this->writeTemplate('cached', '<p>cached</p>');
        $compiler = $this->createCompiler();
        $compiler->compile('cached');

        self::assertFalse($compiler->needsRecompilation('cached'));
    }

    private function createCompiler(): TemplateCompiler
    {
        $config = new ViewConfig(
            templatePaths: [$this->templateDir],
            cachePath: $this->cacheDir,
        );
        $cache = new TemplateCache($this->cacheDir);

        return new TemplateCompiler($config, $cache);
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
        $realPath = realpath($path);

        if ($realPath === false || !is_dir($realPath)) {
            return;
        }

        // Guard: only delete within the system temp directory
        $tempBase = realpath(sys_get_temp_dir());

        if ($tempBase === false || !str_starts_with($realPath, $tempBase)) {
            return;
        }

        $items = scandir($realPath);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $realPath . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fullPath)) {
                $this->removeRecursive($fullPath);
            } else {
                unlink($fullPath);
            }
        }

        rmdir($realPath);
    }
}
