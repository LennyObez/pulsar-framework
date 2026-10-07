<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Engine\TemplateCache;
use Pulsar\View\Engine\TemplateCompiler;
use Pulsar\View\Engine\TemplateEngine;
use Pulsar\View\Engine\TemplateInheritance;
use Pulsar\View\ViewConfig;

use function count;
use function dirname;
use function file_put_contents;
use function is_dir;
use function iterator_to_array;
use function mkdir;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function uniqid;

use const DIRECTORY_SEPARATOR;

/**
 * Tests for TemplateEngine::stream() and inheritance edge paths.
 */
#[CoversClass(TemplateEngine::class)]
final class TemplateEngineStreamTest extends TestCase
{
    private string $templateDir;
    private string $cacheDir;
    private TemplateEngine $engine;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_stream_test_' . uniqid();
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
        // Cleanup handled by OS temp dir garbage collection
    }

    #[Test]
    public function streamYieldsChunksOfOutput(): void
    {
        $content = str_repeat('A', 8192);
        $this->writeTemplate('large', $content);

        $chunks = iterator_to_array($this->engine->stream('large', [], 4096));

        self::assertNotEmpty($chunks);
        self::assertSame($content, implode('', $chunks));
    }

    #[Test]
    public function streamYieldsSingleChunkForSmallTemplate(): void
    {
        $this->writeTemplate('small', '<p>Hi</p>');

        $chunks = iterator_to_array($this->engine->stream('small'));

        self::assertCount(1, $chunks);
        self::assertSame('<p>Hi</p>', $chunks[0]);
    }

    #[Test]
    public function streamRespectsChunkSize(): void
    {
        $content = str_repeat('X', 100);
        $this->writeTemplate('chunked', $content);

        $chunks = iterator_to_array($this->engine->stream('chunked', [], 30));

        foreach ($chunks as $i => $chunk) {
            if ($i < count($chunks) - 1) {
                self::assertSame(30, strlen($chunk));
            }
        }

        self::assertSame($content, implode('', $chunks));
    }

    #[Test]
    public function renderWithExternallySuppliedEnv(): void
    {
        $this->writeTemplate('child', '<?php $__env->startSection("title"); ?>Custom Title<?php $__env->endSection(); ?>Body');

        $env = new TemplateInheritance();
        $result = $this->engine->render('child', ['__env' => $env]);

        self::assertStringContainsString('Body', $result);
        self::assertSame('Custom Title', $env->yieldSection('title'));
    }

    #[Test]
    public function renderInjectsAuthHelperWhenNotProvided(): void
    {
        $this->writeTemplate('auth-check', '<?php echo $__auth->guest() ? "guest" : "user"; ?>');

        $result = $this->engine->render('auth-check');

        self::assertSame('guest', $result);
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
}
