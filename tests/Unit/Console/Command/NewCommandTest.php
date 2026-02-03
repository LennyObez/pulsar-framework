<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use function dirname;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\NewCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

#[CoversClass(NewCommand::class)]
final class NewCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_new_cmd_test_' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_has_the_correct_name(): void
    {
        $command = new NewCommand();

        self::assertSame('new', $command->name);
    }

    #[Test]
    public function it_has_a_description(): void
    {
        $command = new NewCommand();

        self::assertNotEmpty($command->description);
        self::assertStringContainsString('project', strtolower($command->description));
    }

    #[Test]
    public function it_has_a_name_argument(): void
    {
        $command = new NewCommand();

        self::assertNotEmpty($command->arguments);
        self::assertSame('name', $command->arguments[0]['name']);
        self::assertTrue($command->arguments[0]['required']);
    }

    #[Test]
    public function it_has_a_preset_option(): void
    {
        $command = new NewCommand();

        self::assertArrayHasKey('preset', $command->options);
        self::assertSame('web', $command->options['preset']['default']);
    }

    #[Test]
    public function it_has_an_env_option(): void
    {
        $command = new NewCommand();

        self::assertArrayHasKey('env', $command->options);
        self::assertSame('local', $command->options['env']['default']);
    }

    #[Test]
    public function it_returns_invalid_when_name_is_missing(): void
    {
        $command = new NewCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn(null);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Invalid->value, $result);
    }

    #[Test]
    public function it_returns_invalid_when_name_is_empty_string(): void
    {
        $command = new NewCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('');

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Invalid->value, $result);
    }

    #[Test]
    public function it_returns_invalid_when_name_is_not_a_string(): void
    {
        $command = new NewCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn(42);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Invalid->value, $result);
    }

    #[Test]
    public function it_creates_a_project_with_default_presets(): void
    {
        $command = new NewCommand();

        $originalDir = getcwd();
        chdir(dirname($this->tempDir));

        $dirName = basename($this->tempDir);
        $input = $this->createInputStub($dirName, 'web', 'local');
        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Success->value, $result);
        self::assertDirectoryExists($this->tempDir);
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . '.env');
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php');
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'composer.json');
    }

    #[Test]
    public function it_returns_error_when_directory_already_exists(): void
    {
        mkdir($this->tempDir, 0o755, true);

        $command = new NewCommand();
        $dirName = basename($this->tempDir);

        $input = $this->createInputStub($dirName, 'web', 'local');
        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        $originalDir = getcwd();
        chdir(dirname($this->tempDir));

        $result = $command->execute($input, $output);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Error->value, $result);
    }

    #[Test]
    public function it_shows_api_next_steps_for_api_preset(): void
    {
        $command = new NewCommand();
        $dirName = basename($this->tempDir);

        $input = $this->createInputStub($dirName, 'api', 'local');

        $writtenLines = [];
        $output = $this->createStub(OutputInterface::class);
        $output->method('writeln')->willReturnCallback(function (string $msg = '') use (&$writtenLines): void {
            $writtenLines[] = $msg;
        });

        $originalDir = getcwd();
        chdir(dirname($this->tempDir));

        $result = $command->execute($input, $output);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Success->value, $result);

        $allOutput = implode("\n", $writtenLines);
        self::assertStringContainsString('curl', $allOutput);
        self::assertStringContainsString('/health', $allOutput);
    }

    #[Test]
    public function it_shows_browser_next_steps_for_web_preset(): void
    {
        $command = new NewCommand();
        $dirName = basename($this->tempDir);

        $input = $this->createInputStub($dirName, 'web', 'local');

        $writtenLines = [];
        $output = $this->createStub(OutputInterface::class);
        $output->method('writeln')->willReturnCallback(function (string $msg = '') use (&$writtenLines): void {
            $writtenLines[] = $msg;
        });

        $originalDir = getcwd();
        chdir(dirname($this->tempDir));

        $result = $command->execute($input, $output);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Success->value, $result);

        $allOutput = implode("\n", $writtenLines);
        self::assertStringContainsString('browser', strtolower($allOutput));
    }

    #[Test]
    public function it_generates_a_usage_string(): void
    {
        $command = new NewCommand();

        $usage = $command->getUsage();

        self::assertStringContainsString('new', $usage);
        self::assertStringContainsString('<name>', $usage);
    }

    private function createInputStub(string $name, string $preset, string $env): InputInterface
    {
        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn($name);
        $input->method('getOption')->willReturnMap([
            ['preset', 'web', $preset],
            ['env', 'local', $env],
        ]);

        return $input;
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
