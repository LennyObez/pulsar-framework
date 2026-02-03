<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Application;
use Pulsar\Console\Command;
use Pulsar\Console\Command\ListCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\InputInterface;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Console\OutputInterface;
use Pulsar\Core\Kernel;

#[CoversClass(ListCommand::class)]
final class ListCommandTest extends TestCase
{
    private Application $app;
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $kernel = new Kernel();
        $this->app = new Application($kernel);
        $this->output = new BufferedOutput();
    }

    #[Test]
    public function textFormatListsCommands(): void
    {
        $this->app->add($this->makeDummyCommand('test:one', 'First test'));
        $this->app->add($this->makeDummyCommand('test:two', 'Second test'));

        $command = new ListCommand($this->app);
        $input = new ArrayInput('list');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('test:one', $this->output->buffer);
        self::assertStringContainsString('test:two', $this->output->buffer);
        self::assertStringContainsString('First test', $this->output->buffer);
    }

    #[Test]
    public function jsonFormatListsCommands(): void
    {
        $this->app->add($this->makeDummyCommand('test:one', 'First test'));

        $command = new ListCommand($this->app);
        $input = new ArrayInput('list', [], ['format' => 'json']);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var list<array{name: string, description: string, namespace: string}> $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('test:one', $json[0]['name']);
        self::assertSame('First test', $json[0]['description']);
    }

    #[Test]
    public function emptyCommandList(): void
    {
        $command = new ListCommand($this->app);
        $input = new ArrayInput('list');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No commands available', $this->output->buffer);
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $command = new ListCommand($this->app);

        self::assertSame('list', $command->name);
        self::assertNotEmpty($command->description);
    }

    private function makeDummyCommand(string $name, string $description): Command
    {
        return new class ($name, $description) extends Command {
            public function __construct(
                private readonly string $cmdName,
                private readonly string $cmdDescription,
            ) {
                parent::__construct();
            }

            protected function configure(): void
            {
                $this->name = $this->cmdName;
                $this->description = $this->cmdDescription;
            }

            public function execute(InputInterface $input, OutputInterface $output): int
            {
                return ExitCode::Success->value;
            }
        };
    }
}
