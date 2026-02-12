<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Extractor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Extractor\TranslationExtractor;

use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function rmdir;
use function unlink;

#[CoversClass(TranslationExtractor::class)]
final class TranslationExtractorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_test_extractor_' . uniqid();
        mkdir($this->tempDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function extractsDoubleUnderscoreCalls(): void
    {
        file_put_contents(
            $this->tempDir . DIRECTORY_SEPARATOR . 'test.php',
            "<?php\n\$msg = __('welcome');\n",
        );

        $extractor = new TranslationExtractor();
        $result = $extractor->extract($this->tempDir, $this->tempDir);

        self::assertArrayHasKey('messages', $result->keys);
        self::assertArrayHasKey('welcome', $result->keys['messages']);
    }

    #[Test]
    public function extractsTransCalls(): void
    {
        file_put_contents(
            $this->tempDir . DIRECTORY_SEPARATOR . 'test.php',
            "<?php\n\$msg = trans('greeting');\n",
        );

        $extractor = new TranslationExtractor();
        $result = $extractor->extract($this->tempDir, $this->tempDir);

        self::assertArrayHasKey('greeting', $result->keys['messages']);
    }

    #[Test]
    public function extractsTranslateMethodCalls(): void
    {
        file_put_contents(
            $this->tempDir . DIRECTORY_SEPARATOR . 'test.php',
            "<?php\n\$msg = \$translator->translate('item.name');\n",
        );

        $extractor = new TranslationExtractor();
        $result = $extractor->extract($this->tempDir, $this->tempDir);

        self::assertArrayHasKey('item.name', $result->keys['messages']);
    }

    #[Test]
    public function ignoresNonPhpFiles(): void
    {
        file_put_contents(
            $this->tempDir . DIRECTORY_SEPARATOR . 'test.txt',
            "__('should_not_be_found')",
        );

        $extractor = new TranslationExtractor();
        $result = $extractor->extract($this->tempDir, $this->tempDir);

        self::assertSame(0, $result->totalKeys());
    }

    #[Test]
    public function extractsFromSubdirectories(): void
    {
        $subDir = $this->tempDir . DIRECTORY_SEPARATOR . 'sub';
        mkdir($subDir);

        file_put_contents(
            $subDir . DIRECTORY_SEPARATOR . 'nested.php',
            "<?php\n__('nested.key');\n",
        );

        $extractor = new TranslationExtractor();
        $result = $extractor->extract($this->tempDir, $this->tempDir);

        self::assertArrayHasKey('nested.key', $result->keys['messages']);
    }

    #[Test]
    public function totalKeysCountsCorrectly(): void
    {
        file_put_contents(
            $this->tempDir . DIRECTORY_SEPARATOR . 'test.php',
            "<?php\n__('key1');\ntrans('key2');\n__('key1');\n",
        );

        $extractor = new TranslationExtractor();
        $result = $extractor->extract($this->tempDir, $this->tempDir);

        self::assertSame(2, $result->totalKeys());
    }

    #[Test]
    public function normalizesPathsToForwardSlashes(): void
    {
        $subDir = $this->tempDir . DIRECTORY_SEPARATOR . 'src';
        mkdir($subDir);

        file_put_contents(
            $subDir . DIRECTORY_SEPARATOR . 'file.php',
            "<?php\n__('path.test');\n",
        );

        $extractor = new TranslationExtractor();
        $result = $extractor->extract($this->tempDir, $this->tempDir);

        $references = $result->keys['messages']['path.test'];

        foreach ($references as $ref) {
            self::assertStringNotContainsString('\\', $ref);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*');

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->removeDir($file);
            } else {
                unlink($file);
            }
        }

        rmdir($dir);
    }
}
