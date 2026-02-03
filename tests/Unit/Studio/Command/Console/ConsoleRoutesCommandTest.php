<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Studio\Command\Console\ConsoleRoutesCommand;
use Pulsar\Studio\Console\Aggregation\DashboardAggregatorInterface;

#[CoversClass(ConsoleRoutesCommand::class)]
final class ConsoleRoutesCommandTest extends TestCase
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
        $command = new ConsoleRoutesCommand($aggregator);

        self::assertSame('studio:console:routes', $command->name);
        self::assertSame('Display route performance data from Studio', $command->description);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function executeDisplaysRoutesAsText(): void
    {
        $metrics = [
            'routes' => [
                ['path' => '/api/users', 'hits' => 100, 'avg_ms' => 42.5, 'errors' => 2],
                ['path' => '/api/orders', 'hits' => 50, 'avg_ms' => 85.0, 'errors' => 0],
            ],
        ];

        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn($metrics);
        $command = new ConsoleRoutesCommand($aggregator);
        $input = new ArrayInput('studio:console:routes');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Route Performance', $this->output->buffer);
        self::assertStringContainsString('/api/users', $this->output->buffer);
        self::assertStringContainsString('/api/orders', $this->output->buffer);
    }

    #[Test]
    public function executeDisplaysNoRouteDataMessage(): void
    {
        $metrics = [
            'routes' => [],
        ];

        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn($metrics);
        $command = new ConsoleRoutesCommand($aggregator);
        $input = new ArrayInput('studio:console:routes');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No route data recorded.', $this->output->buffer);
    }

    #[Test]
    public function executeDisplaysNoRouteDataWhenKeyMissing(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn([]);
        $command = new ConsoleRoutesCommand($aggregator);
        $input = new ArrayInput('studio:console:routes');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No route data recorded.', $this->output->buffer);
    }

    #[Test]
    public function executeOutputsJson(): void
    {
        $metrics = [
            'routes' => [
                ['path' => '/api/users', 'hits' => 100, 'avg_ms' => 42.5, 'errors' => 2],
            ],
        ];

        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn($metrics);
        $command = new ConsoleRoutesCommand($aggregator);
        $input = new ArrayInput('studio:console:routes', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{routes: list<array<string, mixed>>}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('studio:console:routes', $json['command']);
        self::assertTrue($json['success']);
        self::assertCount(1, $json['data']['routes']);
        self::assertSame('/api/users', $json['data']['routes'][0]['path']);
    }

    #[Test]
    public function executeOutputsJsonWithEmptyRoutes(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn(['routes' => []]);
        $command = new ConsoleRoutesCommand($aggregator);
        $input = new ArrayInput('studio:console:routes', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{routes: list<mixed>}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame([], $json['data']['routes']);
    }

    #[Test]
    public function executeDisplaysTableHeader(): void
    {
        $metrics = [
            'routes' => [
                ['path' => '/test', 'hits' => 1, 'avg_ms' => 1.0, 'errors' => 0],
            ],
        ];

        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn($metrics);
        $command = new ConsoleRoutesCommand($aggregator);
        $input = new ArrayInput('studio:console:routes');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Path', $this->output->buffer);
        self::assertStringContainsString('Hits', $this->output->buffer);
        self::assertStringContainsString('Avg (ms)', $this->output->buffer);
        self::assertStringContainsString('Errors', $this->output->buffer);
    }
}
