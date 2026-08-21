<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\DataProtectionConfig;
use Pulsar\DataProtection\DataPurgeJob;
use Pulsar\DataProtection\DataPurgeOrchestrator;
use Pulsar\DataProtection\DefaultRetentionPolicy;
use Pulsar\DataProtection\PurgeConfig;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobStatus;
use Pulsar\Scheduler\Schedule;
use Pulsar\Tests\Unit\DataProtection\Support\RecordingPurger;

use function str_contains;

#[CoversClass(DataPurgeJob::class)]
final class DataPurgeJobTest extends TestCase
{
    #[Test]
    public function runningTheJobActuallyRemovesExpiredRecords(): void
    {
        // The effect, not the call: retention was declared and executed by
        // nothing, so the property under test is that the records are gone.
        $purger = new RecordingPurger(['session-1', 'session-2', 'session-3']);

        $result = $this->job($purger)->execute($this->context());

        self::assertSame(JobStatus::Success, $result->status);
        self::assertSame(['session-1', 'session-2', 'session-3'], $purger->purged);
        self::assertSame([], $purger->remaining());
    }

    #[Test]
    public function reportsWhatItRemovedAndFromWhere(): void
    {
        $result = $this->job(new RecordingPurger(['a', 'b']))->execute($this->context());

        self::assertTrue(str_contains($result->output, 'user_sessions=2'), $result->output);
        self::assertTrue(str_contains($result->output, 'retention purge'), $result->output);
    }

    #[Test]
    public function honoursDryRunSoADeploymentCanWatchBeforeItDeletes(): void
    {
        $purger = new RecordingPurger(['a', 'b']);

        $result = $this->job($purger, dryRun: true)->execute($this->context());

        self::assertSame([], $purger->purged, 'a dry run must not delete');
        self::assertSame(['a', 'b'], $purger->remaining());
        self::assertTrue(str_contains($result->output, 'retention dry run'), $result->output);
    }

    #[Test]
    public function saysSoWhenNoCategoryHasBothAPurgerAndAPolicy(): void
    {
        // A retention section naming categories nothing can purge is a gap the
        // operator should see, not a silent success.
        $orchestrator = new DataPurgeOrchestrator(
            purgers: [],
            policies: ['user_sessions' => new DefaultRetentionPolicy('user_sessions', 90)],
            config: new DataProtectionConfig(),
        );

        $result = new DataPurgeJob($orchestrator, Schedule::daily())->execute($this->context());

        self::assertSame(JobStatus::Success, $result->status);
        self::assertTrue(str_contains($result->output, 'nothing ran'), $result->output);
    }

    #[Test]
    public function carriesTheScheduleItWasBuiltWith(): void
    {
        $job = $this->job(new RecordingPurger([]));

        self::assertSame('data-protection:purge', $job->getName());
        self::assertSame('0 3 * * *', $job->getSchedule()->expression);
        self::assertTrue(str_contains($job->getDescription(), 'data_protection.php'));
    }

    private function job(RecordingPurger $purger, bool $dryRun = false): DataPurgeJob
    {
        $orchestrator = new DataPurgeOrchestrator(
            purgers: ['user_sessions' => $purger],
            policies: ['user_sessions' => new DefaultRetentionPolicy('user_sessions', 90, 'Operational necessity')],
            config: new DataProtectionConfig(purge: new PurgeConfig(dryRun: $dryRun)),
        );

        return new DataPurgeJob($orchestrator, new Schedule(PurgeConfig::DEFAULT_SCHEDULE));
    }

    private function context(): JobContext
    {
        return new JobContext(new DateTimeImmutable(), new DateTimeImmutable());
    }
}
