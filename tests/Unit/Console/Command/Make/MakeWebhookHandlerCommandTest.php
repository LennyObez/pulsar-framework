<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Make\MakeWebhookHandlerCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

#[CoversClass(MakeWebhookHandlerCommand::class)]
final class MakeWebhookHandlerCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_make_webhook_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'Billing', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_has_the_correct_name(): void
    {
        self::assertSame('make:webhook-handler', new MakeWebhookHandlerCommand()->name);
    }

    #[Test]
    public function it_returns_invalid_when_name_is_missing(): void
    {
        $command = new MakeWebhookHandlerCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn(null);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        self::assertSame(ExitCode::Invalid->value, $command->execute($input, $output));
    }

    #[Test]
    public function it_creates_webhook_handler_structure(): void
    {
        $command = new MakeWebhookHandlerCommand();

        $originalDir = getcwd();
        chdir($this->tempDir);

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('Stripe');
        $input->method('getOption')->willReturnMap([
            ['module', null, 'Billing'],
            ['path', 'app/Modules', 'app/Modules'],
        ]);

        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Success->value, $result);

        $modulePath = $this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules'
            . DIRECTORY_SEPARATOR . 'Billing';
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'Contracts' . DIRECTORY_SEPARATOR . 'StripeWebhookHandlerInterface.php');
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'StripeWebhookHandler.php');
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'StripeHmacVerifier.php');
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'Controller' . DIRECTORY_SEPARATOR . 'StripeWebhookController.php');
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'StripeWebhookConfig.php');
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'Domain' . DIRECTORY_SEPARATOR . 'StripeWebhookEvent.php');
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'Domain' . DIRECTORY_SEPARATOR . 'StripeWebhookEventType.php');
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
