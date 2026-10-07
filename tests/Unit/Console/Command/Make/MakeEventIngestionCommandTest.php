<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Make\MakeEventIngestionCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\OutputInterface;

#[CoversClass(MakeEventIngestionCommand::class)]
final class MakeEventIngestionCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_make_ingest_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'Integrations', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_has_the_correct_name(): void
    {
        self::assertSame('make:event-ingestion', new MakeEventIngestionCommand()->name);
    }

    #[Test]
    public function it_returns_invalid_when_name_is_missing(): void
    {
        $command = new MakeEventIngestionCommand();

        $input = new ArrayInput(null, [], []);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        self::assertSame(ExitCode::Invalid->value, $command->execute($input, $output));
    }

    #[Test]
    public function it_creates_event_ingestion_structure_with_custom_events(): void
    {
        $command = new MakeEventIngestionCommand();

        $originalDir = getcwd();
        chdir($this->tempDir);

        $input = new ArrayInput(null, ['GitHub'], [
            'module' => 'Integrations',
            'path' => 'app/Modules',
            'events' => 'push,pull_request,issue',
        ]);

        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Success->value, $result);

        $modulePath = $this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules'
            . DIRECTORY_SEPARATOR . 'Integrations';
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'Contracts' . DIRECTORY_SEPARATOR . 'GitHubEventHandlerInterface.php');
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'GitHubEventHandler.php');
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'Controller' . DIRECTORY_SEPARATOR . 'GitHubWebhookController.php');
        self::assertFileExists($modulePath . DIRECTORY_SEPARATOR . 'Domain' . DIRECTORY_SEPARATOR . 'GitHubEventType.php');

        $enumContent = file_get_contents($modulePath . DIRECTORY_SEPARATOR . 'Domain' . DIRECTORY_SEPARATOR . 'GitHubEventType.php');
        self::assertIsString($enumContent);
        self::assertStringContainsString("case Push = 'push'", $enumContent);
        self::assertStringContainsString("case PullRequest = 'pull_request'", $enumContent);
        self::assertStringContainsString("case Issue = 'issue'", $enumContent);
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
