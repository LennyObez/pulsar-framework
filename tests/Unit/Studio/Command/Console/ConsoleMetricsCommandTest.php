<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Studio\Command\Console\ConsoleMetricsCommand;
use Pulsar\Studio\Console\Aggregation\DashboardAggregatorInterface;

#[CoversClass(ConsoleMetricsCommand::class)]
final class ConsoleMetricsCommandTest extends TestCase
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
        $command = new ConsoleMetricsCommand($aggregator);

        self::assertSame('studio:console:metrics', $command->name);
        self::assertSame('Display aggregated Studio metrics', $command->description);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function executeDisplaysMetricsAsText(): void
    {
        $metrics = [
            'total_events' => 150,
            'total_requests' => 80,
            'avg_response_ms' => 42.5,
            'total_exceptions' => 3,
            'total_queries' => 200,
        ];

        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn($metrics);
        $command = new ConsoleMetricsCommand($aggregator);
        $input = new ArrayInput('studio:console:metrics');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Studio Metrics', $this->output->buffer);
        self::assertStringContainsString('Total events:     150', $this->output->buffer);
        self::assertStringContainsString('HTTP requests:    80', $this->output->buffer);
        self::assertStringContainsString('Avg response:     42.5 ms', $this->output->buffer);
        self::assertStringContainsString('Exceptions:       3', $this->output->buffer);
        self::assertStringContainsString('DB queries:       200', $this->output->buffer);
    }

    #[Test]
    public function executeOutputsJson(): void
    {
        $metrics = [
            'total_events' => 100,
            'total_requests' => 50,
            'avg_response_ms' => 25.0,
            'total_exceptions' => 1,
            'total_queries' => 75,
        ];

        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn($metrics);
        $command = new ConsoleMetricsCommand($aggregator);
        $input = new ArrayInput('studio:console:metrics', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array<string, mixed>} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('studio:console:metrics', $json['command']);
        self::assertTrue($json['success']);
        self::assertSame(100, $json['data']['total_events']);
        self::assertSame(50, $json['data']['total_requests']);
    }

    #[Test]
    public function executeHandlesEmptyMetrics(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn([]);
        $command = new ConsoleMetricsCommand($aggregator);
        $input = new ArrayInput('studio:console:metrics');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Total events:     0', $this->output->buffer);
        self::assertStringContainsString('HTTP requests:    0', $this->output->buffer);
        self::assertStringContainsString('Avg response:     0.0 ms', $this->output->buffer);
    }

    #[Test]
    public function executeHandlesPartialMetrics(): void
    {
        $metrics = [
            'total_events' => 10,
        ];

        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn($metrics);
        $command = new ConsoleMetricsCommand($aggregator);
        $input = new ArrayInput('studio:console:metrics');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Total events:     10', $this->output->buffer);
        self::assertStringContainsString('HTTP requests:    0', $this->output->buffer);
    }
}
