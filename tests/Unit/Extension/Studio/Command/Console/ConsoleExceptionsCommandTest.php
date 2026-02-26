<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Command\Console;

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
    #[Test]
    public function configuredCorrectly(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn([]);
        $command = new ConsoleExceptionsCommand($aggregator);

        self::assertSame('studio:console:exceptions', $command->name);
        self::assertArrayHasKey('json', $command->options);
    }

    #[Test]
    public function noExceptionsShowsEmptyMessage(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn(['exceptions' => []]);

        $command = new ConsoleExceptionsCommand($aggregator);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:console:exceptions'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Exception Summary', $output->buffer);
        self::assertStringContainsString('No exceptions recorded', $output->buffer);
    }

    #[Test]
    public function missingExceptionsKeyShowsEmptyMessage(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn([]);

        $command = new ConsoleExceptionsCommand($aggregator);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:console:exceptions'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No exceptions recorded', $output->buffer);
    }

    #[Test]
    public function displaysExceptionTableWithData(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn([
            'exceptions' => [
                [
                    'class' => 'RuntimeException',
                    'count' => 42,
                    'last_seen' => '2026-02-25 10:30',
                ],
                [
                    'class' => 'InvalidArgumentException',
                    'count' => 7,
                    'last_seen' => '2026-02-25 09:15',
                ],
            ],
        ]);

        $command = new ConsoleExceptionsCommand($aggregator);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:console:exceptions'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Exception Summary', $output->buffer);
        self::assertStringContainsString('Class', $output->buffer);
        self::assertStringContainsString('Count', $output->buffer);
        self::assertStringContainsString('Last Seen', $output->buffer);
        self::assertStringContainsString('RuntimeException', $output->buffer);
        self::assertStringContainsString('42', $output->buffer);
        self::assertStringContainsString('InvalidArgumentException', $output->buffer);
        self::assertStringContainsString('7', $output->buffer);
    }

    #[Test]
    public function jsonOutputWithExceptions(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn([
            'exceptions' => [
                [
                    'class' => 'LogicException',
                    'count' => 3,
                    'last_seen' => '2026-02-25 12:00',
                ],
            ],
        ]);

        $command = new ConsoleExceptionsCommand($aggregator);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('studio:console:exceptions', [], ['json' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{exceptions: list<array{class: string, count: int, last_seen: string}>}} $decoded */
        $decoded = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('studio:console:exceptions', $decoded['command']);
        self::assertTrue($decoded['success']);
        self::assertCount(1, $decoded['data']['exceptions']);
        self::assertSame('LogicException', $decoded['data']['exceptions'][0]['class']);
    }

    #[Test]
    public function jsonOutputEmptyExceptions(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn(['exceptions' => []]);

        $command = new ConsoleExceptionsCommand($aggregator);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('studio:console:exceptions', [], ['json' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{exceptions: list<mixed>}} $decoded */
        $decoded = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('studio:console:exceptions', $decoded['command']);
        self::assertTrue($decoded['success']);
        self::assertSame([], $decoded['data']['exceptions']);
    }

    #[Test]
    public function exceptionWithMissingFieldsUsesDefaults(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn([
            'exceptions' => [
                [],
            ],
        ]);

        $command = new ConsoleExceptionsCommand($aggregator);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:console:exceptions'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('unknown', $output->buffer);
    }

    #[Test]
    public function tableHeadersAreAligned(): void
    {
        $aggregator = $this->createStub(DashboardAggregatorInterface::class);
        $aggregator->method('aggregate')->willReturn(['exceptions' => []]);

        $command = new ConsoleExceptionsCommand($aggregator);
        $output = new BufferedOutput();

        $command->execute(new ArrayInput('studio:console:exceptions'), $output);

        self::assertStringContainsString(str_repeat('=', 70), $output->buffer);
        self::assertStringContainsString(str_repeat('-', 70), $output->buffer);
    }
}
