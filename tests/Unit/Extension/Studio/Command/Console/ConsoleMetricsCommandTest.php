<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Command\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Studio\Command\Console\ConsoleMetricsCommand;
use Pulsar\Extension\Studio\Console\Aggregation\DashboardAggregatorInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ConsoleMetricsCommand::class)]
final class ConsoleMetricsCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn([]);
        $command = new ConsoleMetricsCommand($aggregator);

        self::assertSame('studio:console:metrics', $command->name);
        self::assertArrayHasKey('json', $command->options);
    }

    #[Test]
    public function textOutputShowsMetrics(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn([
            'total_events' => 500,
            'total_requests' => 200,
            'avg_response_ms' => 12.5,
            'total_exceptions' => 3,
            'total_queries' => 1500,
        ]);

        $command = new ConsoleMetricsCommand($aggregator);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:console:metrics'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Studio Metrics', $output->buffer);
        self::assertStringContainsString('Total events:     500', $output->buffer);
        self::assertStringContainsString('HTTP requests:    200', $output->buffer);
        self::assertStringContainsString('Avg response:     12.5 ms', $output->buffer);
        self::assertStringContainsString('Exceptions:       3', $output->buffer);
        self::assertStringContainsString('DB queries:       1500', $output->buffer);
    }

    #[Test]
    public function textOutputDefaultsForMissingKeys(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn([]);

        $command = new ConsoleMetricsCommand($aggregator);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:console:metrics'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Total events:     0', $output->buffer);
        self::assertStringContainsString('HTTP requests:    0', $output->buffer);
        self::assertStringContainsString('Avg response:     0.0 ms', $output->buffer);
        self::assertStringContainsString('Exceptions:       0', $output->buffer);
        self::assertStringContainsString('DB queries:       0', $output->buffer);
    }

    #[Test]
    public function jsonOutput(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn([
            'total_events' => 100,
            'total_requests' => 50,
            'avg_response_ms' => 8.3,
            'total_exceptions' => 1,
            'total_queries' => 300,
        ]);

        $command = new ConsoleMetricsCommand($aggregator);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('studio:console:metrics', [], ['json' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{total_events: int, total_requests: int, avg_response_ms: float, total_exceptions: int, total_queries: int}} $decoded */
        $decoded = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('studio:console:metrics', $decoded['command']);
        self::assertTrue($decoded['success']);
        self::assertSame(100, $decoded['data']['total_events']);
        self::assertSame(50, $decoded['data']['total_requests']);
        self::assertSame(8.3, $decoded['data']['avg_response_ms']);
    }

    #[Test]
    public function jsonOutputWithEmptyMetrics(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn([]);

        $command = new ConsoleMetricsCommand($aggregator);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('studio:console:metrics', [], ['json' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array<string, mixed>} $decoded */
        $decoded = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('studio:console:metrics', $decoded['command']);
        self::assertTrue($decoded['success']);
        self::assertSame([], $decoded['data']);
    }
}
