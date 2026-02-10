<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Engine\CompiledTemplate;
use Pulsar\View\Engine\TemplateCache;
use Pulsar\View\ViewException;

use function file_get_contents;
use function is_dir;
use function mkdir;
use function strlen;
use function sys_get_temp_dir;
use function uniqid;

use const DIRECTORY_SEPARATOR;

#[CoversClass(TemplateCache::class)]
final class TemplateCacheTest extends TestCase
{
    private string $cachePath;

    private TemplateCache $cache;

    protected function setUp(): void
    {
        $this->cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_view_test_' . uniqid();
        mkdir($this->cachePath, 0o755, true);
        $this->cache = new TemplateCache($this->cachePath);
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->cachePath);
    }

    #[Test]
    public function getReturnsNullWhenCacheMiss(): void
    {
        $result = $this->cache->get('layouts.main', '<h1>Hello</h1>');

        self::assertNull($result);
    }

    #[Test]
    public function putStoresCompiledTemplateAndGetRetrievesIt(): void
    {
        $source = '<h1>{{ $title }}</h1>';
        $compiled = '<?php echo htmlspecialchars($title); ?>';

        $result = $this->cache->put('home.index', $source, $compiled);

        self::assertInstanceOf(CompiledTemplate::class, $result);
        self::assertSame(TemplateCache::hash($source), $result->sourceHash);
        self::assertFileExists($result->compiledPath);
        self::assertSame($compiled, file_get_contents($result->compiledPath));

        $retrieved = $this->cache->get('home.index', $source);

        self::assertNotNull($retrieved);
        self::assertSame($result->compiledPath, $retrieved->compiledPath);
        self::assertSame($result->sourceHash, $retrieved->sourceHash);
    }

    #[Test]
    public function getReturnsNullWhenContentChanges(): void
    {
        $source = '<h1>Hello</h1>';
        $compiled = '<?php echo "Hello"; ?>';

        $this->cache->put('test', $source, $compiled);

        $result = $this->cache->get('test', '<h1>Changed</h1>');

        self::assertNull($result);
    }

    #[Test]
    public function hasReturnsTrueWhenCacheValid(): void
    {
        $source = '<p>Test</p>';
        $this->cache->put('test', $source, '<?php echo "Test"; ?>');

        self::assertTrue($this->cache->has('test', $source));
    }

    #[Test]
    public function hasReturnsFalseWhenCacheMissing(): void
    {
        self::assertFalse($this->cache->has('nonexistent', 'content'));
    }

    #[Test]
    public function hasReturnsFalseWhenContentChanged(): void
    {
        $this->cache->put('test', 'original', '<?php echo "original"; ?>');

        self::assertFalse($this->cache->has('test', 'modified'));
    }

    #[Test]
    public function forgetRemovesCachedFiles(): void
    {
        $source = '<p>Remove me</p>';
        $result = $this->cache->put('to-remove', $source, '<?php echo "remove"; ?>');

        self::assertFileExists($result->compiledPath);

        $this->cache->forget('to-remove', $result->sourceHash);

        self::assertFileDoesNotExist($result->compiledPath);
        self::assertFileDoesNotExist($result->compiledPath . '.meta');
    }

    #[Test]
    public function forgetDoesNothingForNonexistentEntry(): void
    {
        $this->cache->forget('nonexistent', 'somehash');

        // Forgetting a non-existent entry is a safe no-op — the cache reports it still absent
        self::assertFalse($this->cache->has('nonexistent', 'somehash'));
    }

    #[Test]
    public function flushRemovesAllCachedFiles(): void
    {
        $this->cache->put('a', 'source-a', 'compiled-a');
        $this->cache->put('b', 'source-b', 'compiled-b');

        $this->cache->flush();

        self::assertFalse($this->cache->has('a', 'source-a'));
        self::assertFalse($this->cache->has('b', 'source-b'));
    }

    #[Test]
    public function flushOnNonexistentDirectoryDoesNothing(): void
    {
        $cache = new TemplateCache('/nonexistent/path');

        $cache->flush();

        // Flushing a cache pointing at a nonexistent directory is a safe no-op
        self::assertFalse($cache->has('any', 'hash'));
    }

    #[Test]
    public function hashReturnsDeterministicSha256(): void
    {
        $content = 'Hello, World!';
        $hash1 = TemplateCache::hash($content);
        $hash2 = TemplateCache::hash($content);

        self::assertSame($hash1, $hash2);
        self::assertSame(64, strlen($hash1));
    }

    #[Test]
    public function hashReturnsDifferentValuesForDifferentContent(): void
    {
        self::assertNotSame(
            TemplateCache::hash('content-a'),
            TemplateCache::hash('content-b'),
        );
    }

    #[Test]
    public function putCreatesSubdirectoriesAutomatically(): void
    {
        $result = $this->cache->put('deeply.nested.template', 'source', 'compiled');

        self::assertFileExists($result->compiledPath);
    }

    #[Test]
    public function putThrowsOnUnwritableDirectory(): void
    {
        // Place a regular file where the cache directory needs to be created,
        // so mkdir fails because a non-directory file already exists at the path.
        $blocker = $this->cachePath . DIRECTORY_SEPARATOR . 'blocker';
        file_put_contents($blocker, 'occupied');

        $cache = new TemplateCache($blocker . DIRECTORY_SEPARATOR . 'sub');

        $this->expectException(ViewException::class);

        $cache->put('test', 'source', 'compiled');
    }

    #[Test]
    public function sameContentDifferentNamesProducesDifferentPaths(): void
    {
        $source = 'identical content';
        $result1 = $this->cache->put('template-a', $source, 'compiled-a');
        $result2 = $this->cache->put('template-b', $source, 'compiled-b');

        self::assertNotSame($result1->compiledPath, $result2->compiledPath);
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
