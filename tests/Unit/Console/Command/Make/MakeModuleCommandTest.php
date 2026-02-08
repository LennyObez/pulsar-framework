<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Make\MakeModuleCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

#[CoversClass(MakeModuleCommand::class)]
final class MakeModuleCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_make_module_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_has_the_correct_name(): void
    {
        $command = new MakeModuleCommand();

        self::assertSame('make:module', $command->name);
    }

    #[Test]
    public function it_returns_invalid_when_name_is_missing(): void
    {
        $command = new MakeModuleCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn(null);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        self::assertSame(ExitCode::Invalid->value, $command->execute($input, $output));
    }

    #[Test]
    public function it_scaffolds_module_with_contracts_separation(): void
    {
        $command = new MakeModuleCommand();

        $originalDir = getcwd();
        chdir($this->tempDir);

        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules', 0o755, true);

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('billing');
        $input->method('getOption')->willReturnMap([
            ['path', 'app/Modules', 'app/Modules'],
            ['with-config', null, null],
            ['with-tests', null, null],
        ]);
        $input->method('hasOption')->willReturn(false);

        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Success->value, $result);

        $modulePath = $this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'Billing';
        self::assertDirectoryExists($modulePath . DIRECTORY_SEPARATOR . 'Contracts');
        self::assertDirectoryExists($modulePath . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure');
        self::assertDirectoryExists($modulePath . DIRECTORY_SEPARATOR . 'Controller');
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'Contracts' . DIRECTORY_SEPARATOR . 'BillingServiceInterface.php');
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'BillingService.php');
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'ModuleServiceProvider.php');
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'routes.php');
    }

    #[Test]
    public function it_returns_error_when_module_already_exists(): void
    {
        $command = new MakeModuleCommand();

        $originalDir = getcwd();
        chdir($this->tempDir);

        $modulePath = $this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'Billing';
        mkdir($modulePath, 0o755, true);

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('billing');
        $input->method('getOption')->willReturnMap([
            ['path', 'app/Modules', 'app/Modules'],
        ]);
        $input->method('hasOption')->willReturn(false);

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
