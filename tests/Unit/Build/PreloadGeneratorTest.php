<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Build;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Build\PreloadGenerator;
use Pulsar\Runtime\RuntimeType;

use function bin2hex;
use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function scandir;
use function str_contains;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(PreloadGenerator::class)]
final class PreloadGeneratorTest extends TestCase
{
    private string $basePath;
    private string $cacheDir;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_preload_test_' . $suffix;
        $this->cacheDir = $this->basePath . DIRECTORY_SEPARATOR . 'cache';

        mkdir($this->cacheDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
    }

    #[Test]
    public function fpmRuntimeProducesDifferentPreloadThanPersistent(): void
    {
        // Create some source files the generator looks for
        $this->createSourceFile('src/Core/Kernel.php');
        $this->createSourceFile('src/Container/Container.php');
        $this->createSourceFile('src/Http/Message/ServerRequest.php');
        $this->createSourceFile('src/Http/Message/Response.php');
        $this->createSourceFile('src/Runtime/PersistentRuntime.php');

        $generator = new PreloadGenerator();

        $fpmOutput = $generator->generate(RuntimeType::Fpm, $this->cacheDir);
        $persistentOutput = $generator->generate(RuntimeType::Persistent, $this->cacheDir);

        self::assertNotSame($fpmOutput, $persistentOutput);

        // FPM should include HTTP-specific files
        self::assertTrue(str_contains($fpmOutput, 'ServerRequest.php'));

        // Persistent should include persistent runtime files
        self::assertTrue(str_contains($persistentOutput, 'PersistentRuntime.php'));
    }

    #[Test]
    public function outputUsesForwardSlashesOnly(): void
    {
        $this->createSourceFile('src/Core/Kernel.php');

        $generator = new PreloadGenerator();
        $output = $generator->generate(RuntimeType::Fpm, $this->cacheDir);

        // Check no backslashes in file paths within require_once/opcache_compile_file
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            if (str_contains($line, "require_once '") || str_contains($line, "opcache_compile_file('")) {
                self::assertStringNotContainsString('\\', $line, 'Path should use forward slashes: ' . $line);
            }
        }
    }

    #[Test]
    public function outputHasNoTimestamps(): void
    {
        $this->createSourceFile('src/Core/Kernel.php');

        $generator = new PreloadGenerator();
        $output = $generator->generate(RuntimeType::Fpm, $this->cacheDir);

        // Ensure no date/time patterns in output (e.g., 2026-02-18, 12:30:00)
        self::assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2}/', $output);
        self::assertDoesNotMatchRegularExpression('/\d{2}:\d{2}:\d{2}/', $output);
    }

    #[Test]
    public function deterministicOutputForSameInput(): void
    {
        $this->createSourceFile('src/Core/Kernel.php');
        $this->createSourceFile('src/Core/Version.php');
        $this->createSourceFile('src/Container/Container.php');

        $generator = new PreloadGenerator();

        $output1 = $generator->generate(RuntimeType::Fpm, $this->cacheDir);
        $output2 = $generator->generate(RuntimeType::Fpm, $this->cacheDir);

        self::assertSame($output1, $output2);
    }

    #[Test]
    public function frameworkClassesArePreloadedRegardlessOfProjectLayout(): void
    {
        // The base path here contains no source files at all. The generator used
        // to resolve `Pulsar\…` to `<basePath>/src/…`, so any project without a
        // literal src/ — an installed app (framework under vendor/) or a PSR-4
        // root named app/ — produced "No classes to preload": preload was a
        // silent no-op exactly where it mattered. Classes are now resolved
        // through the autoloader, so the core set is always emitted.
        $generator = new PreloadGenerator();
        $output = $generator->generate(RuntimeType::Fpm, $this->cacheDir);

        self::assertStringContainsString('<?php', $output);
        self::assertStringContainsString('declare(strict_types=1)', $output);
        self::assertStringNotContainsString('No classes to preload', $output);
        self::assertStringContainsString('Kernel.php', $output);
    }

    #[Test]
    public function artifactFilesAreIncludedWithOpcacheCompileFile(): void
    {
        $this->createSourceFile('src/Core/Kernel.php');

        // Create artifact files in the cache dir
        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'container.compiled.php', '<?php return [];');
        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'routes.compiled.php', '<?php return [];');

        $generator = new PreloadGenerator();
        $output = $generator->generate(RuntimeType::Fpm, $this->cacheDir);

        self::assertStringContainsString('opcache_compile_file(', $output);
        self::assertStringContainsString('container.compiled.php', $output);
        self::assertStringContainsString('routes.compiled.php', $output);
    }

    private function createSourceFile(string $relativePath): void
    {
        $fullPath = $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }

        file_put_contents($fullPath, '<?php // stub for testing');
    }

    private function removeDirectory(string $dir): void
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

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
