<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\I18nExtractCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\I18n\Extractor\TranslationExtractor;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(I18nExtractCommand::class)]
final class I18nExtractCommandTest extends TestCase
{
    private string $tempDir;

    #[Override]
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_i18n_extract_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $command = new I18nExtractCommand(new TranslationExtractor(), $this->tempDir);

        self::assertSame('i18n:extract', $command->name);
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function extractsAndWritesJson(): void
    {
        $srcDir = $this->tempDir . DIRECTORY_SEPARATOR . 'src';
        mkdir($srcDir, 0o750, true);

        file_put_contents(
            $srcDir . DIRECTORY_SEPARATOR . 'Example.php',
            "<?php\n\n__('greeting.hello');\n__('greeting.goodbye');\n",
        );

        $command = new I18nExtractCommand(new TranslationExtractor(), $this->tempDir);
        $output = new BufferedOutput();
        $input = new ArrayInput('i18n:extract', [], ['output' => 'var/i18n/extracted.json']);

        $exit = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Extracted 2 keys', $output->buffer);

        $outputFile = $this->tempDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'i18n' . DIRECTORY_SEPARATOR . 'extracted.json';
        self::assertFileExists($outputFile);
    }

    #[Test]
    public function extractsZeroKeysFromEmptyDir(): void
    {
        $srcDir = $this->tempDir . DIRECTORY_SEPARATOR . 'src';
        mkdir($srcDir, 0o750, true);

        $command = new I18nExtractCommand(new TranslationExtractor(), $this->tempDir);
        $output = new BufferedOutput();
        $input = new ArrayInput('i18n:extract', [], ['output' => 'var/i18n/extracted.json']);

        $exit = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Extracted 0 keys', $output->buffer);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        /** @var list<string> $items */
        $items = scandir($dir);

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
