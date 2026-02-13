<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Make\MakeAdapterCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Console\OutputInterface;

#[CoversClass(MakeAdapterCommand::class)]
final class MakeAdapterCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_make_adapter_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'Billing', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_has_the_correct_name(): void
    {
        self::assertSame('make:adapter', new MakeAdapterCommand()->name);
    }

    #[Test]
    public function it_returns_invalid_when_name_is_missing(): void
    {
        $command = new MakeAdapterCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn(null);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        self::assertSame(ExitCode::Invalid->value, $command->execute($input, $output));
    }

    #[Test]
    public function it_returns_invalid_when_module_is_missing(): void
    {
        $command = new MakeAdapterCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('StripeProvider');
        $input->method('getOption')->willReturnMap([
            ['port', null, 'PaymentProvider'],
            ['module', null, null],
            ['path', 'app/Modules', 'app/Modules'],
        ]);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        self::assertSame(ExitCode::Invalid->value, $command->execute($input, $output));
    }

    #[Test]
    public function it_returns_invalid_when_port_is_missing(): void
    {
        $command = new MakeAdapterCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('StripeProvider');
        $input->method('getOption')->willReturnMap([
            ['port', null, null],
            ['module', null, 'Billing'],
            ['path', 'app/Modules', 'app/Modules'],
        ]);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        self::assertSame(ExitCode::Invalid->value, $command->execute($input, $output));
    }

    #[Test]
    public function it_returns_error_when_module_does_not_exist(): void
    {
        $command = new MakeAdapterCommand();

        $originalDir = getcwd();
        chdir($this->tempDir);

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('StripeProvider');
        $input->method('getOption')->willReturnMap([
            ['port', null, 'PaymentProvider'],
            ['module', null, 'NonExistent'],
            ['path', 'app/Modules', 'app/Modules'],
        ]);

        $output = new BufferedOutput();

        $result = $command->execute($input, $output);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Error->value, $result);
        self::assertStringContainsString('does not exist', $output->errorBuffer);
    }

    #[Test]
    public function it_creates_adapter_implementing_port(): void
    {
        $command = new MakeAdapterCommand();

        $originalDir = getcwd();
        chdir($this->tempDir);

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('StripeProvider');
        $input->method('getOption')->willReturnMap([
            ['port', null, 'PaymentProvider'],
            ['module', null, 'Billing'],
            ['path', 'app/Modules', 'app/Modules'],
        ]);

        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Success->value, $result);

        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules'
            . DIRECTORY_SEPARATOR . 'Billing' . DIRECTORY_SEPARATOR . 'Internal'
            . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'StripeProvider.php';
        self::assertFileExists($file);

        $content = file_get_contents($file);
        self::assertIsString($content);
        self::assertStringContainsString('implements PaymentProviderInterface', $content);
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
