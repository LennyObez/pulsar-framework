<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Command\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Studio\Command\Console\ConsoleRoutesCommand;
use Pulsar\Extension\Studio\Console\Aggregation\DashboardAggregatorInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ConsoleRoutesCommand::class)]
final class ConsoleRoutesCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn([]);
        $command = new ConsoleRoutesCommand($aggregator);

        self::assertSame('studio:console:routes', $command->name);
        self::assertArrayHasKey('json', $command->options);
    }

    #[Test]
    public function noRouteDataShowsEmptyMessage(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn(['routes' => []]);

        $command = new ConsoleRoutesCommand($aggregator);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:console:routes'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Route Performance', $output->buffer);
        self::assertStringContainsString('No route data recorded', $output->buffer);
    }

    #[Test]
    public function displaysRouteTable(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn([
            'routes' => [
                ['path' => '/api/users', 'hits' => 1200, 'avg_ms' => 15.3, 'errors' => 2],
                ['path' => '/api/orders', 'hits' => 500, 'avg_ms' => 42.7, 'errors' => 0],
            ],
        ]);

        $command = new ConsoleRoutesCommand($aggregator);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:console:routes'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Route Performance', $output->buffer);
        self::assertStringContainsString('/api/users', $output->buffer);
        self::assertStringContainsString('/api/orders', $output->buffer);
        self::assertStringContainsString('1200', $output->buffer);
        self::assertStringContainsString('15.3', $output->buffer);
    }

    #[Test]
    public function jsonOutput(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn([
            'routes' => [
                ['path' => '/api/users', 'hits' => 100, 'avg_ms' => 5.0, 'errors' => 1],
            ],
        ]);

        $command = new ConsoleRoutesCommand($aggregator);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('studio:console:routes', [], ['json' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{routes: list<array{path: string, hits: int}>}} $decoded */
        $decoded = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('studio:console:routes', $decoded['command']);
        self::assertTrue($decoded['success']);
        self::assertCount(1, $decoded['data']['routes']);
        self::assertSame('/api/users', $decoded['data']['routes'][0]['path']);
    }

    #[Test]
    public function missingRoutesKeyShowsEmptyMessage(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn([]);

        $command = new ConsoleRoutesCommand($aggregator);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:console:routes'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No route data recorded', $output->buffer);
    }
}
