<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Studio\Command\Console\ConsoleExceptionsCommand;
use Pulsar\Extension\Studio\Console\Aggregation\DashboardAggregatorInterface;

#[CoversClass(ConsoleExceptionsCommand::class)]
final class ConsoleExceptionsCommandTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $command = new ConsoleExceptionsCommand($aggregator);

        self::assertSame('studio:console:exceptions', $command->name);
        self::assertSame('Display exception data from Studio', $command->description);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function executeDisplaysExceptionsAsText(): void
    {
        $metrics = [
            'exceptions' => [
                ['class' => 'RuntimeException', 'count' => 15, 'last_seen' => '2024-01-01 12:00'],
                ['class' => 'InvalidArgumentException', 'count' => 3, 'last_seen' => '2024-01-01 11:00'],
            ],
        ];

        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn($metrics);

        $command = new ConsoleExceptionsCommand($aggregator);
        $input = new ArrayInput('studio:console:exceptions');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Exception Summary', $this->output->buffer);
        self::assertStringContainsString('RuntimeException', $this->output->buffer);
        self::assertStringContainsString('InvalidArgumentException', $this->output->buffer);
    }

    #[Test]
    public function executeDisplaysNoExceptionsMessage(): void
    {
        $metrics = [
            'exceptions' => [],
        ];

        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn($metrics);

        $command = new ConsoleExceptionsCommand($aggregator);
        $input = new ArrayInput('studio:console:exceptions');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No exceptions recorded.', $this->output->buffer);
    }

    #[Test]
    public function executeDisplaysNoExceptionsWhenKeyMissing(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn([]);

        $command = new ConsoleExceptionsCommand($aggregator);
        $input = new ArrayInput('studio:console:exceptions');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No exceptions recorded.', $this->output->buffer);
    }

    #[Test]
    public function executeOutputsJson(): void
    {
        $metrics = [
            'exceptions' => [
                ['class' => 'RuntimeException', 'count' => 15, 'last_seen' => '2024-01-01 12:00'],
            ],
        ];

        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn($metrics);

        $command = new ConsoleExceptionsCommand($aggregator);
        $input = new ArrayInput('studio:console:exceptions', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{exceptions: list<array<string, mixed>>}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('studio:console:exceptions', $json['command']);
        self::assertTrue($json['success']);
        self::assertCount(1, $json['data']['exceptions']);
        self::assertSame('RuntimeException', $json['data']['exceptions'][0]['class']);
    }

    #[Test]
    public function executeDisplaysTableHeader(): void
    {
        $metrics = [
            'exceptions' => [
                ['class' => 'RuntimeException', 'count' => 1, 'last_seen' => '2024-01-01'],
            ],
        ];

        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn($metrics);

        $command = new ConsoleExceptionsCommand($aggregator);
        $input = new ArrayInput('studio:console:exceptions');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Class', $this->output->buffer);
        self::assertStringContainsString('Count', $this->output->buffer);
        self::assertStringContainsString('Last Seen', $this->output->buffer);
    }

    #[Test]
    public function executeHandlesExceptionsWithMissingFields(): void
    {
        $metrics = [
            'exceptions' => [
                ['class' => null, 'count' => null, 'last_seen' => null],
            ],
        ];

        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn($metrics);

        $command = new ConsoleExceptionsCommand($aggregator);
        $input = new ArrayInput('studio:console:exceptions');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('unknown', $this->output->buffer);
    }
}
