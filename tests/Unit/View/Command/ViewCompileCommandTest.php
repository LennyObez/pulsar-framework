<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\View\Command\ViewCompileCommand;
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

#[CoversClass(ViewCompileCommand::class)]
final class ViewCompileCommandTest extends TestCase
{
    private string $templateDir;

    private string $cacheDir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_compile_cmd_' . uniqid();
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
    public function configuredCorrectly(): void
    {
        $command = $this->createCommand();

        self::assertSame('view:compile', $command->name);
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function compilesTemplatesSuccessfully(): void
    {
        $this->writeTemplate('home', '<h1>{{ $title }}</h1>');
        $this->writeTemplate('about', '<p>About page</p>');

        $command = $this->createCommand();
        $input = new ArrayInput('view:compile');
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    #[Test]
    public function reportsNonexistentTemplatePath(): void
    {
        $config = new ViewConfig(
            templatePaths: ['/nonexistent/path'],
            cachePath: $this->cacheDir,
        );
        $cache = new TemplateCache($this->cacheDir);
        $compiler = new TemplateCompiler($config, $cache);
        $command = new ViewCompileCommand($compiler, $config);

        $input = new ArrayInput('view:compile');
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    #[Test]
    public function handlesEmptyTemplateDirectory(): void
    {
        $command = $this->createCommand();
        $input = new ArrayInput('view:compile');
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    private function createCommand(): ViewCompileCommand
    {
        $config = new ViewConfig(
            templatePaths: [$this->templateDir],
            cachePath: $this->cacheDir,
        );
        $cache = new TemplateCache($this->cacheDir);
        $compiler = new TemplateCompiler($config, $cache);

        return new ViewCompileCommand($compiler, $config);
    }

    private function writeTemplate(string $name, string $content): void
    {
        $path = $this->templateDir . DIRECTORY_SEPARATOR . $name . '.pulsar.php';
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        file_put_contents($path, $content);
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
