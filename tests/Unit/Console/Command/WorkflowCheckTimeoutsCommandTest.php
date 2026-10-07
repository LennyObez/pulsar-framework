<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\WorkflowCheckTimeoutsCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Workflow\Event\WorkflowTimedOutEvent;
use Pulsar\Workflow\Storage\ClassifiedContext;
use Pulsar\Workflow\Storage\WorkflowInstance;
use Pulsar\Workflow\Storage\WorkflowInstanceStatus;
use Pulsar\Workflow\Storage\WorkflowStorageInterface;

#[CoversClass(WorkflowCheckTimeoutsCommand::class)]
final class WorkflowCheckTimeoutsCommandTest extends TestCase
{
    #[Test]
    public function command_has_correct_name(): void
    {
        $command = new WorkflowCheckTimeoutsCommand($this->createStub(WorkflowStorageInterface::class));

        self::assertSame('workflow:check-timeouts', $command->name);
    }

    #[Test]
    public function dispatches_one_event_per_expired_instance_and_clears_each_deadline(): void
    {
        $expired = [
            $this->instance('wf-1', 'order-approval', 'awaiting_review'),
            $this->instance('wf-2', 'order-approval', 'awaiting_payment'),
        ];

        $cleared = [];
        $storage = $this->createStub(WorkflowStorageInterface::class);
        $storage->method('findExpiredTimeouts')->willReturn($expired);
        $storage->method('updateTimeout')->willReturnCallback(
            static function (string $id, ?DateTimeImmutable $timeoutAt) use (&$cleared): void {
                $cleared[] = [$id, $timeoutAt];
            },
        );

        $dispatched = [];
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            },
        );

        $command = new WorkflowCheckTimeoutsCommand($storage, $dispatcher);
        $exit = $command->execute(new ArrayInput(arguments: []), new BufferedOutput());

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertSame([['wf-1', null], ['wf-2', null]], $cleared, 'each deadline is cleared exactly once');
        self::assertCount(2, $dispatched);
        self::assertInstanceOf(WorkflowTimedOutEvent::class, $dispatched[0]);
        self::assertSame('wf-1', $dispatched[0]->instanceId);
        self::assertSame('awaiting_review', $dispatched[0]->currentState);
    }

    #[Test]
    public function reports_success_with_no_expired_instances(): void
    {
        $storage = $this->createStub(WorkflowStorageInterface::class);
        $storage->method('findExpiredTimeouts')->willReturn([]);

        $command = new WorkflowCheckTimeoutsCommand($storage);
        $exit = $command->execute(new ArrayInput(arguments: []), new BufferedOutput());

        self::assertSame(ExitCode::Success->value, $exit);
    }

    private function instance(string $id, string $definitionId, string $state): WorkflowInstance
    {
        return new WorkflowInstance(
            id: $id,
            definitionId: $definitionId,
            definitionVersion: 1,
            currentState: $state,
            context: new ClassifiedContext(),
            version: 1,
            status: WorkflowInstanceStatus::Active,
            startedAt: new DateTimeImmutable('-1 hour'),
            completedAt: null,
            startedBy: 'tester',
            timeoutAt: new DateTimeImmutable('-5 minutes'),
        );
    }
}
