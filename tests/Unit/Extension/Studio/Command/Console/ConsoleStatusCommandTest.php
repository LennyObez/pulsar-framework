<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Command\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Studio\Command\Console\ConsoleStatusCommand;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ConsoleStatusCommand::class)]
final class ConsoleStatusCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('count')->willReturn(0);
        $store->method('sizeInBytes')->willReturn(0);
        $command = new ConsoleStatusCommand($store);

        self::assertSame('studio:console:status', $command->name);
        self::assertArrayHasKey('json', $command->options);
    }

    #[Test]
    public function textOutputShowsEventCountAndSize(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('count')->willReturn(150);
        $store->method('sizeInBytes')->willReturn(32768);

        $command = new ConsoleStatusCommand($store);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:console:status'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Console Event Store Status', $output->buffer);
        self::assertStringContainsString('Events:  150', $output->buffer);
        self::assertStringContainsString('Size:    32768 bytes', $output->buffer);
    }

    #[Test]
    public function textOutputWithZeroEvents(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('count')->willReturn(0);
        $store->method('sizeInBytes')->willReturn(0);

        $command = new ConsoleStatusCommand($store);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:console:status'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Events:  0', $output->buffer);
        self::assertStringContainsString('Size:    0 bytes', $output->buffer);
    }

    #[Test]
    public function jsonOutput(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('count')->willReturn(42);
        $store->method('sizeInBytes')->willReturn(8192);

        $command = new ConsoleStatusCommand($store);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('studio:console:status', [], ['json' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{event_count: int, size_bytes: int} $data */
        $data = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(42, $data['event_count']);
        self::assertSame(8192, $data['size_bytes']);
    }
}
