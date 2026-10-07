<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Remove;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Remove\RemoveEventIngestionCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\OutputInterface;

#[CoversClass(RemoveEventIngestionCommand::class)]
final class RemoveEventIngestionCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_rm_ingest_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_has_the_correct_name(): void
    {
        $command = new RemoveEventIngestionCommand();
        self::assertSame('remove:event-ingestion', $command->name);
    }

    #[Test]
    public function it_returns_invalid_when_name_is_missing(): void
    {
        $command = new RemoveEventIngestionCommand();

        $input = new ArrayInput(null, [], []);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        self::assertSame(ExitCode::Invalid->value, $command->execute($input, $output));
    }

    #[Test]
    public function it_removes_ingestion_files_with_force(): void
    {
        $originalDir = getcwd();
        chdir($this->tempDir);

        $modulePath = $this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'Billing';

        mkdir($modulePath . DIRECTORY_SEPARATOR . 'Contracts', 0o755, true);
        mkdir($modulePath . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure', 0o755, true);
        mkdir($modulePath . DIRECTORY_SEPARATOR . 'Controller', 0o755, true);
        mkdir($modulePath . DIRECTORY_SEPARATOR . 'Config', 0o755, true);
        mkdir($modulePath . DIRECTORY_SEPARATOR . 'Domain', 0o755, true);

        $sampleFiles = [
            'Contracts' . DIRECTORY_SEPARATOR . 'GitHubEventHandlerInterface.php',
            'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'GitHubEventHandler.php',
            'Controller' . DIRECTORY_SEPARATOR . 'GitHubWebhookController.php',
            'Config' . DIRECTORY_SEPARATOR . 'GitHubIngestionConfig.php',
            'Domain' . DIRECTORY_SEPARATOR . 'GitHubEvent.php',
        ];

        foreach ($sampleFiles as $file) {
            file_put_contents($modulePath . DIRECTORY_SEPARATOR . $file, '<?php');
        }

        $command = new RemoveEventIngestionCommand();

        $input = new ArrayInput(null, ['GitHub'], [
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
