<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Workflow\Event\WorkflowTimedOutEvent;
use Pulsar\Workflow\Storage\WorkflowStorageInterface;

use function count;
use function sprintf;

/**
 * The polling pump for workflow state timeouts (ADR-0027).
 *
 * PollingTimeoutHandler stores deadlines on the instance; this command --
 * meant to run on a schedule (cron or the framework scheduler) -- detects
 * expired instances, clears each deadline so the expiry fires exactly once,
 * and dispatches a WorkflowTimedOutEvent per instance. The application
 * listens and reacts with its own workflow definitions (escalate, fail,
 * notify), which the framework cannot know.
 *
 * Usage: workflow:check-timeouts
 */
#[Internal]
final class WorkflowCheckTimeoutsCommand extends Command
{
    private const int BATCH_LIMIT = 100;

    public function __construct(
        private readonly WorkflowStorageInterface $storage,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'workflow:check-timeouts';
        $this->description = 'Detect workflow instances whose state timeout expired and dispatch WorkflowTimedOutEvent for each';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $expired = $this->storage->findExpiredTimeouts(self::BATCH_LIMIT);

        if ($expired === []) {
            $output->info('No expired workflow timeouts.');

            return ExitCode::Success->value;
        }

        $now = new DateTimeImmutable();

        foreach ($expired as $instance) {
            // Clear the deadline FIRST so a crash mid-loop cannot re-fire the
            // same expiry on the next run (at-most-once event semantics).
            $this->storage->updateTimeout($instance->id, null);

            $this->eventDispatcher?->dispatch(new WorkflowTimedOutEvent(
                instanceId: $instance->id,
                definitionId: $instance->definitionId,
                definitionVersion: $instance->definitionVersion,
                currentState: $instance->currentState,
                timedOutAt: $instance->timeoutAt ?? $now,
                occurredAt: $now,
            ));

            $output->info(sprintf(
                'Timed out: %s (definition %s v%d, state "%s")',
                $instance->id,
                $instance->definitionId,
                $instance->definitionVersion,
                $instance->currentState,
            ));
        }

        $output->success(sprintf('%d expired workflow timeout(s) dispatched.', count($expired)));

        return ExitCode::Success->value;
    }
}
