<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Remove;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Remove\RemoveFeatureCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

#[CoversClass(RemoveFeatureCommand::class)]
final class RemoveFeatureCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_rm_feat_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_has_the_correct_name(): void
    {
        $command = new RemoveFeatureCommand();

        self::assertSame('remove:feature', $command->name);
    }

    #[Test]
    public function it_returns_invalid_when_name_is_missing(): void
    {
        $command = new RemoveFeatureCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn(null);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        self::assertSame(ExitCode::Invalid->value, $command->execute($input, $output));
    }

    #[Test]
    public function it_returns_invalid_when_module_is_missing(): void
    {
        $command = new RemoveFeatureCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('CreateOrder');
        $input->method('getOption')->willReturnMap([
            ['module', null, null],
        ]);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        self::assertSame(ExitCode::Invalid->value, $command->execute($input, $output));
    }

    #[Test]
    public function it_removes_feature_with_force_flag(): void
    {
        $originalDir = getcwd();
        chdir($this->tempDir);

        $modulePath = $this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'Billing';
        $featurePath = $modulePath . DIRECTORY_SEPARATOR . 'Features' . DIRECTORY_SEPARATOR . 'CreateOrder';
        mkdir($featurePath . DIRECTORY_SEPARATOR . 'Contracts', 0o755, true);
        file_put_contents($featurePath . DIRECTORY_SEPARATOR . 'CreateOrderHandler.php', '<?php');

        $command = new RemoveFeatureCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('CreateOrder');
        $input->method('getOption')->willReturnMap([
            ['module', null, 'Billing'],
            ['path', 'app/Modules', 'app/Modules'],
        ]);
        $input->method('hasOption')->willReturnCallback(fn(string $name): bool => $name === 'force');

        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Success->value, $result);
        self::assertDirectoryDoesNotExist($featurePath);
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
