<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\ABTest;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\ABTest\Experiment;
use Pulsar\Extension\Cms\ABTest\ExperimentStatus;

#[CoversClass(Experiment::class)]
final class ExperimentTest extends TestCase
{
    private function createDraft(): Experiment
    {
        return new Experiment(
            id: 'exp-1',
            name: 'Test Experiment',
            contentId: 'content-1',
            status: ExperimentStatus::Draft,
            trafficPercentage: 0.5,
            startAt: null,
            endAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }

    #[Test]
    public function start_transitions_to_running_and_sets_startAt(): void
    {
        $experiment = $this->createDraft();
        $started = $experiment->start();

        self::assertSame(ExperimentStatus::Running, $started->status);
        self::assertNotNull($started->startAt);
        self::assertNull($started->endAt);
    }

    #[Test]
    public function stop_transitions_to_completed_and_sets_endAt(): void
    {
        $experiment = $this->createDraft()->start();
        $stopped = $experiment->stop();

        self::assertSame(ExperimentStatus::Completed, $stopped->status);
        self::assertNotNull($stopped->endAt);
    }

    #[Test]
    public function cancel_transitions_to_cancelled_and_sets_endAt(): void
    {
        $experiment = $this->createDraft()->start();
        $cancelled = $experiment->cancel();

        self::assertSame(ExperimentStatus::Cancelled, $cancelled->status);
        self::assertNotNull($cancelled->endAt);
    }

    #[Test]
    public function isRunning_returns_true_only_for_running_status(): void
    {
        $draft = $this->createDraft();
        self::assertFalse($draft->isRunning());

        $running = $draft->start();
        self::assertTrue($running->isRunning());

        $completed = $running->stop();
        self::assertFalse($completed->isRunning());

        $cancelled = $draft->start()->cancel();
        self::assertFalse($cancelled->isRunning());
    }

    #[Test]
    public function start_preserves_original_fields(): void
    {
        $experiment = $this->createDraft();
        $started = $experiment->start();

        self::assertSame('exp-1', $started->id);
        self::assertSame('Test Experiment', $started->name);
        self::assertSame('content-1', $started->contentId);
        self::assertSame(0.5, $started->trafficPercentage);
    }
}
