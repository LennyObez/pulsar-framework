<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Compliance\Evidence\EvidenceStoreInterface;
use Pulsar\Compliance\Evidence\InMemoryEvidenceStore;
use Pulsar\Compliance\Verification\ComplianceVerificationEngine;
use Pulsar\Compliance\Verification\ConflictDetector;
use Pulsar\Compliance\Verification\CustomControlRegistry;
use Pulsar\Compliance\Verification\EvidenceChain;
use Pulsar\Compliance\Verification\EvidenceCollectionJob;
use Pulsar\Compliance\Verification\RuntimeVerifier;
use Pulsar\Compliance\Verification\VerificationConfig;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobStatus;
use Pulsar\Scheduler\Schedule;
use Pulsar\Testing\Clock\TestClock;

use function str_contains;
use function str_repeat;

#[CoversClass(EvidenceCollectionJob::class)]
final class EvidenceCollectionJobTest extends TestCase
{
    private const int ONE_HOUR = 3600;

    #[Test]
    public function theFirstRunRecordsEvidence(): void
    {
        $store = new InMemoryEvidenceStore();
        $job = $this->job($store, self::ONE_HOUR, new DateTimeImmutable());

        $result = $job->execute($this->context());

        self::assertSame(JobStatus::Success, $result->status);
        self::assertSame(1, $store->countForControl(EvidenceChain::CONTROL_ID));
    }

    #[Test]
    public function recordsAgainOnlyOnceTheConfiguredIntervalHasElapsed(): void
    {
        // This is what `verification.evidence_interval` means, and what no
        // scheduler was reading: the number gates the recording.
        // Times are relative to real now because EvidenceChain stamps each record
        // with the system clock; the job's clock has to be on the same timeline.
        $store = new InMemoryEvidenceStore();

        $this->job($store, self::ONE_HOUR, new DateTimeImmutable())
            ->execute($this->context());

        $tooSoon = $this->job($store, self::ONE_HOUR, new DateTimeImmutable('+30 minutes'))
            ->execute($this->context());

        self::assertSame(JobStatus::Skipped, $tooSoon->status);
        self::assertSame(1, $store->countForControl(EvidenceChain::CONTROL_ID));

        $dueNow = $this->job($store, self::ONE_HOUR, new DateTimeImmutable('+61 minutes'))
            ->execute($this->context());

        self::assertSame(JobStatus::Success, $dueNow->status);
        self::assertSame(2, $store->countForControl(EvidenceChain::CONTROL_ID));
    }

    #[Test]
    public function theIntervalIsReadFromTheStoreSoARestartDoesNotResetIt(): void
    {
        // `scheduler:tick` is a fresh process on every tick. An in-memory "last
        // run" would be reset by each one, turning the interval into "every tick".
        $store = new InMemoryEvidenceStore();

        $this->job($store, self::ONE_HOUR, new DateTimeImmutable())
            ->execute($this->context());

        // A brand-new job object, as a new process would build: same store, same
        // answer.
        $afterRestart = $this->job($store, self::ONE_HOUR, new DateTimeImmutable('+5 minutes'))
            ->execute($this->context());

        self::assertSame(JobStatus::Skipped, $afterRestart->status);
        self::assertSame(1, $store->countForControl(EvidenceChain::CONTROL_ID));
    }

    #[Test]
    public function saysSoWhenARunProducedNoDurableRecord(): void
    {
        $store = new InMemoryEvidenceStore();
        $engine = $this->engine(evidenceChain: null);

        $result = new EvidenceCollectionJob(
            engine: $engine,
            store: $store,
            intervalSeconds: self::ONE_HOUR,
            schedule: Schedule::hourly(),
            clock: new TestClock(new DateTimeImmutable()),
        )->execute($this->context());

        self::assertSame(JobStatus::Success, $result->status);
        self::assertTrue(str_contains($result->output, 'NOT stored'), $result->output);
        self::assertSame(0, $store->countForControl(EvidenceChain::CONTROL_ID));
    }

    #[Test]
    public function anIntervalOfAnHourOrMoreIsConsideredHourly(): void
    {
        self::assertSame('0 * * * *', EvidenceCollectionJob::scheduleFor(self::ONE_HOUR)->expression);
        self::assertSame('0 * * * *', EvidenceCollectionJob::scheduleFor(86_400)->expression);
    }

    #[Test]
    public function aShorterIntervalIsNeverRoundedUpPastItself(): void
    {
        // Rounding up would silently lengthen the interval the operator set.
        self::assertSame('*/5 * * * *', EvidenceCollectionJob::scheduleFor(300)->expression);
        self::assertSame('*/1 * * * *', EvidenceCollectionJob::scheduleFor(90)->expression);
        self::assertSame('* * * * *', EvidenceCollectionJob::scheduleFor(30)->expression);
    }

    #[Test]
    public function carriesTheSchedulerTimezoneIntoTheSchedule(): void
    {
        self::assertSame('Europe/Brussels', EvidenceCollectionJob::scheduleFor(self::ONE_HOUR, 'Europe/Brussels')->timezone);
    }

    #[Test]
    public function namesItselfAndItsInterval(): void
    {
        $job = $this->job(new InMemoryEvidenceStore(), 1800, new DateTimeImmutable());

        self::assertSame('compliance:collect-evidence', $job->getName());
        self::assertTrue(str_contains($job->getDescription(), '1800'), $job->getDescription());
        self::assertSame('0 * * * *', $job->getSchedule()->expression);
    }

    private function job(EvidenceStoreInterface $store, int $interval, DateTimeImmutable $now): EvidenceCollectionJob
    {
        return new EvidenceCollectionJob(
            engine: $this->engine(new EvidenceChain($store, str_repeat('k', 32))),
            store: $store,
            intervalSeconds: $interval,
            schedule: Schedule::hourly(),
            clock: new TestClock($now),
        );
    }

    private function engine(?EvidenceChain $evidenceChain): ComplianceVerificationEngine
    {
        $profile = new ComplianceProfile(
            enabledFrameworks: [ComplianceFramework::Gdpr],
            passwordMinLength: 8,
            sessionIdleTimeout: 900,
            breachNotificationHours: 72,
            auditRetentionDays: 365,
            dataRetentionDays: 365,
            mfaRequirement: 'none',
            encryptionAtRest: false,
            encryptionInTransit: false,
            tamperEvidentAudit: false,
            explicitConsent: false,
            consentWithdrawal: false,
            individualNotification: false,
            breachRegister: false,
        );

        return new ComplianceVerificationEngine(
            profile: $profile,
            runtimeVerifier: new RuntimeVerifier($profile),
            conflictDetector: new ConflictDetector(),
            customControlRegistry: new CustomControlRegistry(),
            config: new VerificationConfig(),
            evidenceChain: $evidenceChain,
        );
    }

    private function context(): JobContext
    {
        return new JobContext(new DateTimeImmutable(), new DateTimeImmutable());
    }
}
