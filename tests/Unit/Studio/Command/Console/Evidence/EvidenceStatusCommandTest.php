<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console\Evidence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Studio\Command\Console\Evidence\EvidenceStatusCommand;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;

#[CoversClass(EvidenceStatusCommand::class)]
final class EvidenceStatusCommandTest extends TestCase
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
        $command = new EvidenceStatusCommand($this->store);

        self::assertSame('studio:console:evidence:status', $command->name);
        self::assertSame('Display evidence store status', $command->description);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function executeDisplaysStatusAsText(): void
    {
        $this->store->method('count')->willReturn(500);
        $this->store->method('sizeInBytes')->willReturn(1_048_576);

        $command = new EvidenceStatusCommand($this->store);
        $input = new ArrayInput('studio:console:evidence:status');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Evidence Store Status', $this->output->buffer);
        self::assertStringContainsString('Total events:  500', $this->output->buffer);
        self::assertStringContainsString('1.00 MB', $this->output->buffer);
        self::assertStringContainsString('1048576 bytes', $this->output->buffer);
    }

    #[Test]
    public function executeOutputsJson(): void
    {
        $this->store->method('count')->willReturn(250);
        $this->store->method('sizeInBytes')->willReturn(524_288);

        $command = new EvidenceStatusCommand($this->store);
        $input = new ArrayInput('studio:console:evidence:status', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{total_events: int, size_bytes: int, size_mb: float}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('studio:console:evidence:status', $json['command']);
        self::assertTrue($json['success']);
        self::assertSame(250, $json['data']['total_events']);
        self::assertSame(524_288, $json['data']['size_bytes']);
        self::assertSame(0.5, $json['data']['size_mb']);
    }

    #[Test]
    public function executeHandlesEmptyStore(): void
    {
        $this->store->method('count')->willReturn(0);
        $this->store->method('sizeInBytes')->willReturn(0);

        $command = new EvidenceStatusCommand($this->store);
        $input = new ArrayInput('studio:console:evidence:status');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Total events:  0', $this->output->buffer);
        self::assertStringContainsString('0.00 MB', $this->output->buffer);
    }

    #[Test]
    public function executeHandlesLargeStore(): void
    {
        $this->store->method('count')->willReturn(1_000_000);
        $this->store->method('sizeInBytes')->willReturn(1_073_741_824);

        $command = new EvidenceStatusCommand($this->store);
        $input = new ArrayInput('studio:console:evidence:status', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{total_events: int, size_bytes: int, size_mb: float}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertSame(1_000_000, $json['data']['total_events']);
        self::assertSame(1_073_741_824, $json['data']['size_bytes']);
        self::assertSame(1024, $json['data']['size_mb']);
    }
}
