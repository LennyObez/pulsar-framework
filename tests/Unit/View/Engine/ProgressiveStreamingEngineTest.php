<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\DirectiveRegistry;
use Pulsar\View\Engine\ProgressiveStreamingEngine;
use Pulsar\View\Engine\TemplateCache;
use Pulsar\View\Engine\TemplateCompiler;
use Pulsar\View\ViewConfig;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function file_put_contents;
use function implode;
use function is_dir;
use function iterator_to_array;
use function mkdir;
use function rmdir;
use function str_replace;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(ProgressiveStreamingEngine::class)]
final class ProgressiveStreamingEngineTest extends TestCase
{
    private string $templateDir;
    private string $cacheDir;
    private ProgressiveStreamingEngine $engine;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_progressive_test_' . uniqid();
        $this->templateDir = $base . DIRECTORY_SEPARATOR . 'views';
        $this->cacheDir = $base . DIRECTORY_SEPARATOR . 'cache';
        mkdir($this->templateDir, 0o755, true);
        mkdir($this->cacheDir, 0o755, true);

        $config = new ViewConfig(
            templatePaths: [$this->templateDir],
            cachePath: $this->cacheDir,
        );
        $compiler = new TemplateCompiler($config, new TemplateCache($this->cacheDir));
        $directives = new DirectiveRegistry($config);
        $directives->registerBuiltins();
        $directives->bindTo($compiler);
        $this->engine = new ProgressiveStreamingEngine($compiler);
    }

    protected function tearDown(): void
    {
        $base = dirname($this->templateDir);

        if (!is_dir($base)) {
            return;
        }

        /** @var iterable<SplFileInfo> $items */
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($base);
    }

    #[Test]
    public function streamYieldsCompleteContent(): void
    {
        $this->writeTemplate('basic', '<p>{{ $name }}</p>');

        $output = implode('', iterator_to_array($this->engine->stream('basic', ['name' => 'World'])));

        self::assertSame('<p>World</p>', $output);
    }

    #[Test]
    public function streamRendersExtendedLayout(): void
    {
        // FR-6: progressive streaming of a template that @extends a layout must
        // yield the full composed layout. The child buffers its @section content
        // into $env and emits no direct output, so without resolving inheritance
        // the stream would be blank.
        $this->writeTemplate('layout', '<html><body>@yield(\'content\')</body></html>');
        $this->writeTemplate('page', "@extends('layout')@section('content')<h1>Hi</h1>@endsection");

        $output = implode('', iterator_to_array($this->engine->stream('page')));

        self::assertStringContainsString('<h1>Hi</h1>', $output);
        self::assertStringContainsString('<html><body>', $output);
        self::assertStringContainsString('</body></html>', $output);
    }

    private function writeTemplate(string $name, string $content): void
    {
        $fullPath = $this->templateDir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $name) . '.pulse.php';
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        file_put_contents($fullPath, $content);
    }
}
