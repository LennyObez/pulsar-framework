<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Remove;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Remove\RemovePaymentFlowCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

#[CoversClass(RemovePaymentFlowCommand::class)]
final class RemovePaymentFlowCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_rm_pay_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_has_the_correct_name(): void
    {
        $command = new RemovePaymentFlowCommand();
        self::assertSame('remove:payment-flow', $command->name);
    }

    #[Test]
    public function it_returns_invalid_when_name_is_missing(): void
    {
        $command = new RemovePaymentFlowCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn(null);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        self::assertSame(ExitCode::Invalid->value, $command->execute($input, $output));
    }

    #[Test]
    public function it_removes_payment_flow_files_with_force(): void
    {
        $originalDir = getcwd();
        chdir($this->tempDir);

        $modulePath = $this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'Billing';

        mkdir($modulePath . DIRECTORY_SEPARATOR . 'Contracts', 0o755, true);
        mkdir($modulePath . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure', 0o755, true);
        mkdir($modulePath . DIRECTORY_SEPARATOR . 'Gateway', 0o755, true);
        mkdir($modulePath . DIRECTORY_SEPARATOR . 'Config', 0o755, true);
        mkdir($modulePath . DIRECTORY_SEPARATOR . 'Domain', 0o755, true);
        mkdir($modulePath . DIRECTORY_SEPARATOR . 'Exception', 0o755, true);

        $sampleFiles = [
            'Contracts' . DIRECTORY_SEPARATOR . 'CheckoutProviderInterface.php',
            'Gateway' . DIRECTORY_SEPARATOR . 'CheckoutGateway.php',
            'Config' . DIRECTORY_SEPARATOR . 'CheckoutConfig.php',
            'Domain' . DIRECTORY_SEPARATOR . 'CheckoutIntent.php',
        ];

        foreach ($sampleFiles as $file) {
            file_put_contents($modulePath . DIRECTORY_SEPARATOR . $file, '<?php');
        }

        $command = new RemovePaymentFlowCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('Checkout');
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

        foreach ($sampleFiles as $file) {
            self::assertFileDoesNotExist($modulePath . DIRECTORY_SEPARATOR . $file);
        }
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
