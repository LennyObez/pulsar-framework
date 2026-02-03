<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console\Guardian;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Studio\Command\Console\Guardian\GuardianStatusCommand;
use Pulsar\Studio\Console\Storage\EventStoreInterface;

#[CoversClass(GuardianStatusCommand::class)]
final class GuardianStatusCommandTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
    }

    #[Test]
    public function it_is_configured_correctly(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $command = new GuardianStatusCommand($store);

        self::assertSame('studio:console:guardian:status', $command->name);
        self::assertSame('Display combined guardian status overview', $command->description);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function it_displays_status_as_text(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('count')->willReturn(42);
        $store->method('sizeInBytes')->willReturn(2_097_152); // 2 MB

        $command = new GuardianStatusCommand($store);
        $input = new ArrayInput('studio:console:guardian:status');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Guardian Status', $this->output->buffer);
        self::assertStringContainsString('Evidence Store:', $this->output->buffer);
        self::assertStringContainsString('Events:  42', $this->output->buffer);
        self::assertStringContainsString('2.00 MB', $this->output->buffer);
    }

    #[Test]
    public function it_displays_status_as_json(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('count')->willReturn(100);
        $store->method('sizeInBytes')->willReturn(1_048_576); // 1 MB

        $command = new GuardianStatusCommand($store);
        $input = new ArrayInput('studio:console:guardian:status', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{evidence: array{total_events: int, size_bytes: int, size_mb: float}}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('studio:console:guardian:status', $json['command']);
        self::assertTrue($json['success']);
        self::assertSame(100, $json['data']['evidence']['total_events']);
        self::assertSame(1_048_576, $json['data']['evidence']['size_bytes']);
        self::assertEqualsWithDelta(1.0, $json['data']['evidence']['size_mb'], 0.01);
    }

    #[Test]
    public function it_handles_zero_events(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('count')->willReturn(0);
        $store->method('sizeInBytes')->willReturn(0);

        $command = new GuardianStatusCommand($store);
        $input = new ArrayInput('studio:console:guardian:status');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Events:  0', $this->output->buffer);
        self::assertStringContainsString('0.00 MB', $this->output->buffer);
    }

    #[Test]
    public function it_handles_zero_events_json(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('count')->willReturn(0);
        $store->method('sizeInBytes')->willReturn(0);

        $command = new GuardianStatusCommand($store);
        $input = new ArrayInput('studio:console:guardian:status', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{evidence: array{total_events: int, size_bytes: int, size_mb: float}}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame(0, $json['data']['evidence']['total_events']);
        self::assertSame(0, $json['data']['evidence']['size_bytes']);
        self::assertEqualsWithDelta(0.0, $json['data']['evidence']['size_mb'], 0.01);
    }
}
