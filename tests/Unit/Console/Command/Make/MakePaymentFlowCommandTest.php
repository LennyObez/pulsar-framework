<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Make\MakePaymentFlowCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

#[CoversClass(MakePaymentFlowCommand::class)]
final class MakePaymentFlowCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_make_payment_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'Checkout', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_has_the_correct_name(): void
    {
        self::assertSame('make:payment-flow', new MakePaymentFlowCommand()->name);
    }

    #[Test]
    public function it_returns_invalid_when_name_is_missing(): void
    {
        $command = new MakePaymentFlowCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn(null);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        self::assertSame(ExitCode::Invalid->value, $command->execute($input, $output));
    }

    #[Test]
    public function it_creates_payment_flow_structure(): void
    {
        $command = new MakePaymentFlowCommand();

        $originalDir = getcwd();
        chdir($this->tempDir);

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('Subscription');
        $input->method('getOption')->willReturnMap([
            ['module', null, 'Checkout'],
            ['path', 'app/Modules', 'app/Modules'],
        ]);

        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Success->value, $result);

        $modulePath = $this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules'
            . DIRECTORY_SEPARATOR . 'Checkout';
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'Contracts' . DIRECTORY_SEPARATOR . 'SubscriptionProviderInterface.php');
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'NullSubscriptionProvider.php');
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'Gateway' . DIRECTORY_SEPARATOR . 'SubscriptionGateway.php');
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'Gateway' . DIRECTORY_SEPARATOR . 'ParametersHasher.php');
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'Domain' . DIRECTORY_SEPARATOR . 'SubscriptionIntent.php');
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'Domain' . DIRECTORY_SEPARATOR . 'Money.php');
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'Exception' . DIRECTORY_SEPARATOR . 'SubscriptionException.php');
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'SubscriptionServiceProvider.php');
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
