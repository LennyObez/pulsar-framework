<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Make\MakeEventCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;

#[CoversClass(MakeEventCommand::class)]
final class MakeEventCommandTest extends TestCase
{
    private string $tempDir;
    private string|false $originalDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_event_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o755, true);

        // Create a module structure
        mkdir($this->tempDir . '/app/Modules/User', 0o755, true);

        $this->originalDir = getcwd();
        chdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        if ($this->originalDir !== false) {
            chdir($this->originalDir);
        }
        $this->cleanupDir($this->tempDir);
    }

    #[Test]
    public function it_has_correct_name(): void
    {
        $command = new MakeEventCommand();

        self::assertSame('make:event', $command->name);
    }

    #[Test]
    public function it_returns_invalid_when_name_is_missing(): void
    {
        $command = new MakeEventCommand();

        $input = new ArrayInput(arguments: [], options: ['module' => 'User']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Invalid->value, $exitCode);
    }

    #[Test]
    public function it_returns_invalid_when_module_is_missing(): void
    {
        $command = new MakeEventCommand();

        $input = new ArrayInput(arguments: ['UserRegistered']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Invalid->value, $exitCode);
    }

    #[Test]
    public function it_creates_event_class(): void
    {
        $command = new MakeEventCommand();

        $input = new ArrayInput(
            arguments: ['UserRegistered'],
            options: ['module' => 'User', 'path' => 'app/Modules'],
        );
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);

        $eventFile = $this->tempDir . '/app/Modules/User/Event/UserRegistered.php';
        self::assertFileExists($eventFile);

        $content = file_get_contents($eventFile);
        self::assertNotFalse($content);
        self::assertStringContainsString('class UserRegistered', $content);
        self::assertStringContainsString('readonly class', $content);
        self::assertStringContainsString('DateTimeImmutable', $content);
    }

    #[Test]
    public function it_returns_error_when_event_already_exists(): void
    {
        $eventDir = $this->tempDir . '/app/Modules/User/Event';
        mkdir($eventDir, 0o755, true);
        file_put_contents($eventDir . '/UserRegistered.php', '<?php // existing');

        $command = new MakeEventCommand();

        $input = new ArrayInput(
            arguments: ['UserRegistered'],
            options: ['module' => 'User', 'path' => 'app/Modules'],
        );
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
    }

    private function cleanupDir(string $dir): void
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
                $this->cleanupDir($path);
            } else {
                @unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use
            }
        }

        @rmdir($dir);
    }
}
