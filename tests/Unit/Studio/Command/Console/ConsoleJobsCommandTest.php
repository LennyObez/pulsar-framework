<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console;

use function in_array;
use function is_array;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Studio\Command\Console\ConsoleJobsCommand;
use Pulsar\Studio\Console\Storage\EventStoreInterface;

#[CoversClass(ConsoleJobsCommand::class)]
final class ConsoleJobsCommandTest extends TestCase
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
        $command = new ConsoleJobsCommand($store);

        self::assertSame('studio:console:jobs', $command->name);
        self::assertSame('Display job processing data from Studio', $command->description);
        self::assertArrayHasKey('limit', $command->options);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('l', $command->options['limit']['shortcut']);
        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function executeDisplaysJobEventsAsText(): void
    {
        $events = [
            [
                'timestamp' => '2024-01-01 00:00:00',
                'type' => 'job.queued',
                'payload' => ['job_class' => 'App\\Jobs\\SendEmail'],
            ],
            [
                'timestamp' => '2024-01-01 00:00:01',
                'type' => 'job.completed',
                'payload' => ['job_class' => 'App\\Jobs\\SendEmail'],
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $command = new ConsoleJobsCommand($store);
        $input = new ArrayInput('studio:console:jobs');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Job Activity', $this->output->buffer);
        self::assertStringContainsString('job.queued', $this->output->buffer);
        self::assertStringContainsString('App\\Jobs\\SendEmail', $this->output->buffer);
        self::assertStringContainsString('Total: 2 events', $this->output->buffer);
    }

    #[Test]
    public function executeDisplaysNoJobEventsMessage(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $command = new ConsoleJobsCommand($store);
        $input = new ArrayInput('studio:console:jobs');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No job events recorded.', $this->output->buffer);
    }

    #[Test]
    public function executeOutputsJson(): void
    {
        $events = [
            [
                'timestamp' => '2024-01-01 00:00:00',
                'type' => 'job.queued',
                'payload' => ['job_class' => 'App\\Jobs\\SendEmail'],
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $command = new ConsoleJobsCommand($store);
        $input = new ArrayInput('studio:console:jobs', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{events: list<mixed>, count: int}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('studio:console:jobs', $json['command']);
        self::assertTrue($json['success']);
        self::assertCount(1, $json['data']['events']);
        self::assertSame(1, $json['data']['count']);
    }

    #[Test]
    public function executeRespectsLimitOption(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(
                self::callback(static fn(array $filters): bool => isset($filters['types']) && is_array($filters['types'])),
                25,
            )
            ->willReturn([]);

        $command = new ConsoleJobsCommand($store);
        $input = new ArrayInput('studio:console:jobs', [], ['limit' => '25']);

        $command->execute($input, $this->output);
    }

    #[Test]
    public function executeHandlesJobEventsWithMissingPayload(): void
    {
        $events = [
            [
                'timestamp' => '2024-01-01 00:00:00',
                'type' => 'job.queued',
                'payload' => [],
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $command = new ConsoleJobsCommand($store);
        $input = new ArrayInput('studio:console:jobs');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('unknown', $this->output->buffer);
    }

    #[Test]
    public function limitOptionDefaultsToFifty(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $command = new ConsoleJobsCommand($store);

        self::assertSame('50', $command->options['limit']['default']);
    }

    #[Test]
    public function executeFiltersOnJobEventTypes(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(
                self::callback(static function (array $filters): bool {
                    $types = $filters['types'] ?? [];
                    if (!is_array($types)) {
                        return false;
                    }

                    return in_array('job.queued', $types, true)
                        && in_array('job.processing', $types, true)
                        && in_array('job.completed', $types, true)
                        && in_array('job.failed', $types, true);
                }),
                self::anything(),
            )
            ->willReturn([]);

        $command = new ConsoleJobsCommand($store);
        $input = new ArrayInput('studio:console:jobs');

        $command->execute($input, $this->output);
    }
}
