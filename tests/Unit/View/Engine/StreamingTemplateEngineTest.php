<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\DirectiveRegistry;
use Pulsar\View\Engine\StreamingTemplateEngine;
use Pulsar\View\Engine\TemplateCache;
use Pulsar\View\Engine\TemplateCompiler;
use Pulsar\View\Engine\TemplateProfiler;
use Pulsar\View\ViewConfig;
use Pulsar\View\ViewException;

use function count;
use function dirname;
use function file_put_contents;
use function implode;
use function is_dir;
use function iterator_to_array;
use function mkdir;
use function scandir;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(StreamingTemplateEngine::class)]
final class StreamingTemplateEngineTest extends TestCase
{
    private string $templateDir;

    private string $cacheDir;

    private StreamingTemplateEngine $engine;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_streaming_test_' . uniqid();
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
        $directives = new DirectiveRegistry($config);
        $directives->registerBuiltins();
        $directives->bindTo($compiler);
        $this->engine = new StreamingTemplateEngine($compiler);
    }

    protected function tearDown(): void
    {
        $this->removeRecursive(dirname($this->templateDir));
    }

    // --- stream() basic behavior ---

    #[Test]
    public function streamReturnsGenerator(): void
    {
        $this->writeTemplate('basic', '<h1>Hello</h1>');

        $result = $this->engine->stream('basic');

        self::assertInstanceOf(Generator::class, $result);
    }

    #[Test]
    public function streamYieldsCompleteContent(): void
    {
        $this->writeTemplate('full', '<h1>Hello, World!</h1>');

        $chunks = iterator_to_array($this->engine->stream('full'));
        $output = implode('', $chunks);

        self::assertSame('<h1>Hello, World!</h1>', $output);
    }

    #[Test]
    public function streamRendersExtendedLayout(): void
    {
        // Streaming a template that @extends a layout must yield the full
        // composed layout. The child buffers its @section content into $env and
        // produces no direct output, so without resolving inheritance the stream
        // would be blank.
        $this->writeTemplate('layout', '<html><body>@yield(\'content\')</body></html>');
        $this->writeTemplate('page', "@extends('layout')@section('content')<h1>Hi</h1>@endsection");

        $output = implode('', iterator_to_array($this->engine->stream('page')));

        self::assertStringContainsString('<h1>Hi</h1>', $output);
        self::assertStringContainsString('<html><body>', $output);
        self::assertStringContainsString('</body></html>', $output);
    }

    #[Test]
    public function streamRendersVariables(): void
    {
        $this->writeTemplate('vars', '<p>{{ $name }}</p>');

        $chunks = iterator_to_array($this->engine->stream('vars', ['name' => 'World']));
        $output = implode('', $chunks);

        self::assertStringContainsString('World', $output);
    }

    #[Test]
    public function streamEscapesOutputByDefault(): void
    {
        $this->writeTemplate('escape', '<p>{{ $content }}</p>');

        $chunks = iterator_to_array($this->engine->stream('escape', ['content' => '<script>xss</script>']));
        $output = implode('', $chunks);

        self::assertStringContainsString('&lt;script&gt;', $output);
        self::assertStringNotContainsString('<script>xss</script>', $output);
    }

    #[Test]
    public function streamThrowsForMissingTemplate(): void
    {
        $this->expectException(ViewException::class);

        $generator = $this->engine->stream('nonexistent');
        // Must consume the generator to trigger the exception
        iterator_to_array($generator);
    }

    // --- Chunking behavior ---

    #[Test]
    public function streamRespectsChunkSize(): void
    {
        // Create content larger than default chunk size
        $content = str_repeat('A', 8192);
        $this->writeTemplate('large', $content);

        $chunks = iterator_to_array($this->engine->stream('large', [], 4096));

        // Should produce at least 2 chunks for 8K content with 4K chunk size
        self::assertGreaterThanOrEqual(2, count($chunks));

        $output = implode('', $chunks);
        self::assertSame($content, $output);
    }

    #[Test]
    public function streamSmallContentYieldsSingleChunk(): void
    {
        $this->writeTemplate('small', '<p>Short</p>');

        $chunks = iterator_to_array($this->engine->stream('small'));

        self::assertCount(1, $chunks);
        self::assertSame('<p>Short</p>', $chunks[0]);
    }

    // --- streamFixed() ---

    #[Test]
    public function streamFixedYieldsFixedSizeChunks(): void
    {
        $content = str_repeat('X', 10000);
        $this->writeTemplate('fixed', $content);

        $chunks = iterator_to_array($this->engine->streamFixed('fixed', [], 4096));

        // First chunk should be exactly 4096 bytes
        self::assertSame(4096, strlen($chunks[0]));

        // Second chunk should be exactly 4096 bytes
        self::assertSame(4096, strlen($chunks[1]));

        // Third chunk should be the remainder
        self::assertSame(10000 - 8192, strlen($chunks[2]));

        $output = implode('', $chunks);
        self::assertSame($content, $output);
    }

    #[Test]
    public function streamFixedReturnsGenerator(): void
    {
        $this->writeTemplate('gen', '<p>Gen</p>');

        $result = $this->engine->streamFixed('gen');

        self::assertInstanceOf(Generator::class, $result);
    }

    #[Test]
    public function streamFixedWithSmallContentYieldsSingleChunk(): void
    {
        $this->writeTemplate('tiny', 'Hi');

        $chunks = iterator_to_array($this->engine->streamFixed('tiny'));

        self::assertCount(1, $chunks);
        self::assertSame('Hi', $chunks[0]);
    }

    // --- Deferred block splitting ---

    #[Test]
    public function streamSplitsAtDeferBoundaries(): void
    {
        $html = '<header>Nav</header>'
            . '<pulse-deferred data-slot="sidebar"><template data-deferred-content>Sidebar Content</template></pulse-deferred>'
            . '<main>Main Content</main>';

        $this->writeTemplate('deferred', $html);

        $chunks = iterator_to_array($this->engine->stream('deferred', [], 4096));

        // Should yield at least 3 chunks: before deferred, deferred block, after deferred
        self::assertGreaterThanOrEqual(3, count($chunks));

        // Reconstruct full output
        $output = implode('', $chunks);
        self::assertSame($html, $output);

        // One chunk should contain the deferred block
        $hasDeferChunk = false;

        foreach ($chunks as $chunk) {
            if (str_contains($chunk, '<pulse-deferred')) {
                $hasDeferChunk = true;

                break;
            }
        }

        self::assertTrue($hasDeferChunk, 'Expected a separate chunk for the deferred block');
    }

    #[Test]
    public function streamSplitsMultipleDeferBlocks(): void
    {
        $html = '<div>Before</div>'
            . '<pulse-deferred data-slot="a"><template data-deferred-content>A</template></pulse-deferred>'
            . '<div>Between</div>'
            . '<pulse-deferred data-slot="b"><template data-deferred-content>B</template></pulse-deferred>'
            . '<div>After</div>';

        $this->writeTemplate('multi-defer', $html);

        $chunks = iterator_to_array($this->engine->stream('multi-defer', [], 65536));
        $output = implode('', $chunks);

        self::assertSame($html, $output);

        // Count chunks that contain deferred blocks
        $deferChunkCount = 0;

        foreach ($chunks as $chunk) {
            if (str_contains($chunk, '<pulse-deferred')) {
                $deferChunkCount++;
            }
        }

        self::assertSame(2, $deferChunkCount, 'Expected two separate deferred chunks');
    }

    // --- streamCompiled() ---

    #[Test]
    public function streamCompiledWorksWithPrecompiledTemplate(): void
    {
        $this->writeTemplate('precompiled', '<p>Static content</p>');

        // Compile first
        $compiled = $this->engine->compiler()->compile('precompiled');

        // Then stream from compiled artifact
        $chunks = iterator_to_array($this->engine->streamCompiled($compiled));
        $output = implode('', $chunks);

        self::assertSame('<p>Static content</p>', $output);
    }

    #[Test]
    public function streamCompiledPassesVariables(): void
    {
        $this->writeTemplate('compiled-vars', '<p>{{ $name }}</p>');

        $compiled = $this->engine->compiler()->compile('compiled-vars');

        $chunks = iterator_to_array($this->engine->streamCompiled($compiled, ['name' => 'Alice']));
        $output = implode('', $chunks);

        self::assertStringContainsString('Alice', $output);
    }

    // --- Profiler integration ---

    #[Test]
    public function streamRecordsProfilingWhenProfilerProvided(): void
    {
        $profiler = new TemplateProfiler();
        $config = new ViewConfig(
            templatePaths: [$this->templateDir],
            cachePath: $this->cacheDir,
        );
        $cache = new TemplateCache($this->cacheDir);
        $compiler = new TemplateCompiler($config, $cache);
        $engine = new StreamingTemplateEngine($compiler, $profiler);

        $this->writeTemplate('profiled', '<p>Profiled</p>');

        // Consume the generator to trigger profiling
        iterator_to_array($engine->stream('profiled'));

        $timings = $profiler->timings();

        self::assertArrayHasKey('profiled', $timings);
        self::assertSame(1, $timings['profiled']['count']);
    }

    // --- Compiler access ---

    #[Test]
    public function compilerReturnsCompilerInstance(): void
    {
        self::assertInstanceOf(TemplateCompiler::class, $this->engine->compiler());
    }

    // --- Comments ---

    #[Test]
    public function streamStripsTemplateComments(): void
    {
        $this->writeTemplate('comments', 'Before{{-- hidden --}}After');

        $chunks = iterator_to_array($this->engine->stream('comments'));
        $output = implode('', $chunks);

        self::assertSame('BeforeAfter', $output);
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
