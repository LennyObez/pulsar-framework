<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Studio\Command\Console\ConsoleTimelineCommand;
use Pulsar\Extension\Studio\Console\Aggregation\TimelineBuilderInterface;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;

#[CoversClass(ConsoleTimelineCommand::class)]
final class ConsoleTimelineCommandTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $timeline = $this->createStub(TimelineBuilderInterface::class);
        $command = new ConsoleTimelineCommand($store, $timeline);

        self::assertSame('studio:console:timeline', $command->name);
        self::assertSame('Display a timeline of recent Studio events', $command->description);
        self::assertArrayHasKey('limit', $command->options);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('l', $command->options['limit']['shortcut']);
        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function executeDisplaysEventsAsText(): void
    {
        $events = [
            ['event_id' => 'evt-1', 'event_type' => 'http.request', 'timestamp_us' => 1704067200000000],
        ];

        $entries = [
            ['timestamp' => '2024-01-01 00:00:00', 'type' => 'http.request', 'summary' => 'GET /api/users'],
            ['timestamp' => '2024-01-01 00:00:01', 'type' => 'http.response', 'summary' => '200 OK'],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $timeline = $this->createStub(TimelineBuilderInterface::class);
        $timeline->method('build')->willReturn($entries);
        $command = new ConsoleTimelineCommand($store, $timeline);
        $input = new ArrayInput('studio:console:timeline');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Studio Timeline', $this->output->buffer);
        self::assertStringContainsString('http.request', $this->output->buffer);
        self::assertStringContainsString('GET /api/users', $this->output->buffer);
        self::assertStringContainsString('Total: 2 events', $this->output->buffer);
    }

    #[Test]
    public function executeDisplaysNoEventsMessage(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $timeline = $this->createStub(TimelineBuilderInterface::class);
        $timeline->method('build')->willReturn([]);
        $command = new ConsoleTimelineCommand($store, $timeline);
        $input = new ArrayInput('studio:console:timeline');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No events recorded.', $this->output->buffer);
    }

    #[Test]
    public function executeOutputsJson(): void
    {
        $events = [
            ['event_id' => 'evt-1', 'event_type' => 'http.request'],
        ];

        $entries = [
            ['timestamp' => '2024-01-01 00:00:00', 'type' => 'http.request', 'summary' => 'GET /api'],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $timeline = $this->createStub(TimelineBuilderInterface::class);
        $timeline->method('build')->willReturn($entries);
        $command = new ConsoleTimelineCommand($store, $timeline);
        $input = new ArrayInput('studio:console:timeline', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{entries: list<mixed>, count: int}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('studio:console:timeline', $json['command']);
        self::assertTrue($json['success']);
        self::assertCount(1, $json['data']['entries']);
        self::assertSame(1, $json['data']['count']);
    }

    #[Test]
    public function executeRespectsLimitOption(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with([], 10, 0)
            ->willReturn([]);

        $timeline = $this->createStub(TimelineBuilderInterface::class);
        $timeline->method('build')->willReturn([]);
        $command = new ConsoleTimelineCommand($store, $timeline);
        $input = new ArrayInput('studio:console:timeline', [], ['limit' => '10']);

        $command->execute($input, $this->output);
    }

    #[Test]
    public function executeHandlesEntriesWithMissingFields(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([['event_id' => 'evt-1']]);

        $entries = [
            ['timestamp' => '', 'type' => '', 'summary' => ''],
        ];

        $timeline = $this->createStub(TimelineBuilderInterface::class);
        $timeline->method('build')->willReturn($entries);
        $command = new ConsoleTimelineCommand($store, $timeline);
        $input = new ArrayInput('studio:console:timeline');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Total: 1 events', $this->output->buffer);
    }

    #[Test]
    public function limitOptionDefaultsToFifty(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $timeline = $this->createStub(TimelineBuilderInterface::class);
        $command = new ConsoleTimelineCommand($store, $timeline);

        self::assertSame('50', $command->options['limit']['default']);
    }
}
