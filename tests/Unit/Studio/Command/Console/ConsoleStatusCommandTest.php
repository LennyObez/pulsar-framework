<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Studio\Command\Console\ConsoleStatusCommand;
use Pulsar\Studio\Console\Storage\EventStoreInterface;

#[CoversClass(ConsoleStatusCommand::class)]
final class ConsoleStatusCommandTest extends TestCase
{
    /** @var EventStoreInterface&Stub */
    private EventStoreInterface $store;
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->store = $this->createStub(EventStoreInterface::class);
        $this->output = new BufferedOutput();
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $command = new ConsoleStatusCommand($this->store);

        self::assertSame('studio:console:status', $command->name);
        self::assertSame('Display Console event store status', $command->description);
        self::assertArrayHasKey('json', $command->options);
    }

    #[Test]
    public function executeDisplaysStatusAsText(): void
    {
        $this->store->method('count')->willReturn(42);
        $this->store->method('sizeInBytes')->willReturn(12345);

        $command = new ConsoleStatusCommand($this->store);
        $input = new ArrayInput('studio:console:status');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Console Event Store Status', $this->output->buffer);
        self::assertStringContainsString('Events:  42', $this->output->buffer);
        self::assertStringContainsString('Size:    12345 bytes', $this->output->buffer);
    }

    #[Test]
    public function executeDisplaysStatusAsJson(): void
    {
        $this->store->method('count')->willReturn(100);
        $this->store->method('sizeInBytes')->willReturn(5000);

        $command = new ConsoleStatusCommand($this->store);
        $input = new ArrayInput('studio:console:status', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{event_count: int, size_bytes: int} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame(100, $json['event_count']);
        self::assertSame(5000, $json['size_bytes']);
    }

    #[Test]
    public function executeHandlesEmptyStore(): void
    {
        $this->store->method('count')->willReturn(0);
        $this->store->method('sizeInBytes')->willReturn(0);

        $command = new ConsoleStatusCommand($this->store);
        $input = new ArrayInput('studio:console:status');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Events:  0', $this->output->buffer);
        self::assertStringContainsString('Size:    0 bytes', $this->output->buffer);
    }

    #[Test]
    public function executeHandlesLargeValues(): void
    {
        $this->store->method('count')->willReturn(1_000_000);
        $this->store->method('sizeInBytes')->willReturn(1_073_741_824);

        $command = new ConsoleStatusCommand($this->store);
        $input = new ArrayInput('studio:console:status', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{event_count: int, size_bytes: int} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertSame(1_000_000, $json['event_count']);
        self::assertSame(1_073_741_824, $json['size_bytes']);
    }
}
