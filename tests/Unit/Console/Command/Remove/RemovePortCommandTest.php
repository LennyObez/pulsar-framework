<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Remove;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Remove\RemovePortCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\OutputInterface;

#[CoversClass(RemovePortCommand::class)]
final class RemovePortCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_rm_port_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_has_the_correct_name(): void
    {
        $command = new RemovePortCommand();

        self::assertSame('remove:port', $command->name);
    }

    #[Test]
    public function it_returns_invalid_when_name_is_missing(): void
    {
        $command = new RemovePortCommand();

        $input = new ArrayInput(null, [], []);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        self::assertSame(ExitCode::Invalid->value, $command->execute($input, $output));
    }

    #[Test]
    public function it_removes_port_with_force_flag(): void
    {
        $originalDir = getcwd();
        chdir($this->tempDir);

        $modulePath = $this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'Billing';
        $contractsDir = $modulePath . DIRECTORY_SEPARATOR . 'Contracts';
        mkdir($contractsDir, 0o755, true);
        $portFile = $contractsDir . DIRECTORY_SEPARATOR . 'PaymentProviderInterface.php';
        file_put_contents($portFile, '<?php');

        $command = new RemovePortCommand();

        $input = new ArrayInput(null, ['PaymentProvider'], [
            'module' => 'Billing',
            'path' => 'app/Modules',
            'force' => true,
        ]);

        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Success->value, $result);
        self::assertFileDoesNotExist($portFile);
    }

    #[Test]
    public function it_returns_error_when_port_does_not_exist(): void
    {
        $originalDir = getcwd();
        chdir($this->tempDir);

        $modulePath = $this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'Billing';
        mkdir($modulePath . DIRECTORY_SEPARATOR . 'Contracts', 0o755, true);

        $command = new RemovePortCommand();

        $input = new ArrayInput(null, ['NonExistent'], [
            'module' => 'Billing',
            'path' => 'app/Modules',
        ]);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        $result = $command->execute($input, $output);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Error->value, $result);
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
