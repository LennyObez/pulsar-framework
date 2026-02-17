<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\View\Command\PulseDiffCommand;
use Pulsar\View\Engine\TemplateCache;
use Pulsar\View\Engine\TemplateCompiler;
use Pulsar\View\ViewConfig;

use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(PulseDiffCommand::class)]
final class PulseDiffCommandTest extends TestCase
{
    private string $templateDir;

    private string $cacheDir;

    private PulseDiffCommand $command;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_diff_test_' . uniqid();
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
        $this->command = new PulseDiffCommand($compiler);
    }

    protected function tearDown(): void
    {
        $this->removeRecursive(dirname($this->templateDir));
    }

    #[Test]
    public function executeShowsCompiledOutputForValidTemplate(): void
    {
        file_put_contents(
            $this->templateDir . DIRECTORY_SEPARATOR . 'hello.pulse.php',
            '<h1>{{ $name }}</h1>',
        );

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('hello');

        $output = $this->createStub(OutputInterface::class);

        $exitCode = $this->command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    #[Test]
    public function executeReturnsErrorForMissingTemplate(): void
    {
        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('nonexistent');

        $output = $this->createStub(OutputInterface::class);

        $exitCode = $this->command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
    }

    #[Test]
    public function executeReturnsErrorForEmptyTemplateName(): void
    {
        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('');

        $output = $this->createStub(OutputInterface::class);

        $exitCode = $this->command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
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
