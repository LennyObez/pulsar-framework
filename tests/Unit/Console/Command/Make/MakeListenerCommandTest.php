<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Make\MakeListenerCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;

#[CoversClass(MakeListenerCommand::class)]
final class MakeListenerCommandTest extends TestCase
{
    private string $tempDir;
    private string|false $originalDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_listener_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o755, true);
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
        $command = new MakeListenerCommand();

        self::assertSame('make:listener', $command->name);
    }

    #[Test]
    public function it_returns_invalid_when_name_is_missing(): void
    {
        $command = new MakeListenerCommand();

        $input = new ArrayInput(arguments: [], options: ['module' => 'User', 'event' => 'App\\Event\\Test']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Invalid->value, $exitCode);
    }

    #[Test]
    public function it_returns_invalid_when_event_class_is_missing(): void
    {
        $command = new MakeListenerCommand();

        $input = new ArrayInput(
            arguments: ['SendWelcomeEmail'],
            options: ['module' => 'User', 'path' => 'app/Modules'],
        );
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Invalid->value, $exitCode);
    }

    #[Test]
    public function it_creates_listener_class(): void
    {
        $command = new MakeListenerCommand();

        $input = new ArrayInput(
            arguments: ['SendWelcomeEmail'],
            options: [
                'module' => 'User',
                'event' => 'App\\Modules\\User\\Event\\UserRegistered',
                'path' => 'app/Modules',
            ],
        );
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);

        $listenerFile = $this->tempDir . '/app/Modules/User/Listener/SendWelcomeEmail.php';
        self::assertFileExists($listenerFile);

        $content = file_get_contents($listenerFile);
        self::assertNotFalse($content);
        self::assertStringContainsString('class SendWelcomeEmail', $content);
        self::assertStringContainsString('UserRegistered', $content);
        self::assertStringContainsString('LoggerInterface', $content);
        self::assertStringContainsString('__invoke', $content);
    }

    #[Test]
    public function it_returns_error_when_listener_already_exists(): void
    {
        $listenerDir = $this->tempDir . '/app/Modules/User/Listener';
        mkdir($listenerDir, 0o755, true);
        file_put_contents($listenerDir . '/SendWelcomeEmail.php', '<?php // existing');

        $command = new MakeListenerCommand();

        $input = new ArrayInput(
            arguments: ['SendWelcomeEmail'],
            options: [
                'module' => 'User',
                'event' => 'App\\Event\\UserRegistered',
                'path' => 'app/Modules',
            ],
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
