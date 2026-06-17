<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Make\MakeFeatureCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\OutputInterface;

#[CoversClass(MakeFeatureCommand::class)]
final class MakeFeatureCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_make_feature_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'Billing', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_has_the_correct_name(): void
    {
        self::assertSame('make:feature', new MakeFeatureCommand()->name);
    }

    #[Test]
    public function it_returns_invalid_when_name_is_missing(): void
    {
        $command = new MakeFeatureCommand();

        $input = new ArrayInput(null, [], []);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        self::assertSame(ExitCode::Invalid->value, $command->execute($input, $output));
    }

    #[Test]
    public function it_creates_feature_slice(): void
    {
        $command = new MakeFeatureCommand();

        $originalDir = getcwd();
        chdir($this->tempDir);

        $input = new ArrayInput(null, ['CreateOrder'], [
            'module' => 'Billing',
            'path' => 'app/Modules',
            'method' => 'POST',
        ]);

        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Success->value, $result);

        $featurePath = $this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules'
            . DIRECTORY_SEPARATOR . 'Billing' . DIRECTORY_SEPARATOR . 'Features'
            . DIRECTORY_SEPARATOR . 'CreateOrder';
        self::assertDirectoryExists($featurePath);
        self::assertFileExists($featurePath . DIRECTORY_SEPARATOR . 'Contracts' . DIRECTORY_SEPARATOR . 'CreateOrderHandlerInterface.php');
        self::assertFileExists($featurePath . DIRECTORY_SEPARATOR . 'CreateOrderHandler.php');
        self::assertFileExists($featurePath . DIRECTORY_SEPARATOR . 'CreateOrderHandlerTest.php');
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
