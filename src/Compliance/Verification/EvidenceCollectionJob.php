<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;
use Pulsar\Api\Api;
use Pulsar\Compliance\Evidence\EvidenceRecord;
use Pulsar\Compliance\Evidence\EvidenceStoreInterface;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\Schedule;

use function count;
use function intdiv;
use function sprintf;

/**
 * Runs compliance verification on the interval `config/compliance.php` declares
 * and records the result to the evidence chain.
 *
 * `verification.evidence_interval` had been a number in a config file, carried
 * into {@see VerificationConfig::$evidenceIntervalSeconds} and read by no
 * scheduler: nothing in the framework collected evidence on any interval at all,
 * so a deployment's entire evidence trail was whatever single record its last boot
 * happened to write. This job is what the setting drives.
 *
 * Two mechanisms, and the split is deliberate:
 *
 *  - The cron expression from {@see self::scheduleFor()} decides how often the
 *    scheduler even considers the job. Cron cannot express "every N seconds" for
 *    an arbitrary N, so it is rounded to something cron can say and never rounded
 *    UP past the interval — a schedule coarser than the interval would silently
 *    lengthen it.
 *  - The elapsed check inside {@see self::execute()} decides whether a run
 *    actually records. It reads the last stored record's timestamp rather than
 *    process memory, because `scheduler:tick` is a fresh process on every tick and
 *    an in-memory "last run" would be reset by every one of them, turning the
 *    interval into "every tick".
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class EvidenceCollectionJob implements JobInterface
{
    public const string NAME = 'compliance:collect-evidence';

    /** Minute steps cron can express evenly; see {@see Schedule::everyMinutes()}. */
    private const array EVEN_MINUTE_STEPS = [30, 20, 15, 12, 10, 6, 5, 4, 3, 2, 1];

    private const int SECONDS_PER_MINUTE = 60;
    private const int SECONDS_PER_HOUR = 3600;

    public function __construct(
        private ComplianceVerificationEngine $engine,
        private EvidenceStoreInterface $store,
        private int $intervalSeconds,
        private Schedule $schedule,
        private ?ClockInterface $clock = null,
    ) {}

    /**
     * The coarsest cron expression that still fires at least as often as the
     * configured interval.
     *
     * An interval of an hour or more is checked hourly; anything shorter picks the
     * largest even minute step that does not exceed it, so the job is considered
     * at least once per interval and the elapsed check does the rest. Rounding the
     * other way would make a 90-second interval fire every two minutes, which is
     * not the interval the operator configured.
     */
    public static function scheduleFor(int $intervalSeconds, string $timezone = 'UTC'): Schedule
    {
        if ($intervalSeconds >= self::SECONDS_PER_HOUR) {
            return Schedule::hourly($timezone);
        }

        $minutes = intdiv($intervalSeconds, self::SECONDS_PER_MINUTE);

        foreach (self::EVEN_MINUTE_STEPS as $step) {
            if ($step <= $minutes) {
                return Schedule::everyMinutes($step, $timezone);
            }
        }

        return Schedule::everyMinute($timezone);
    }

    #[Override]
    public function getName(): string
    {
        return self::NAME;
    }

    #[Override]
    public function getSchedule(): Schedule
    {
        return $this->schedule;
    }

    #[Override]
    public function getDescription(): string
    {
        return sprintf(
            'Runs compliance verification and appends a signed evidence record, at most once '
                . 'every %d seconds (config/compliance.php verification.evidence_interval).',
            $this->intervalSeconds,
        );
    }

    #[Override]
    public function execute(JobContext $context): JobResult
    {
        $now = $this->clock?->now() ?? new DateTimeImmutable();
        $startedAt = $context->startedAt;
        $last = $this->latestRecord();

        if ($last !== null) {
            $elapsed = $now->getTimestamp() - $last->collectedAt->getTimestamp();

            if ($elapsed < $this->intervalSeconds) {
                return JobResult::skipped($this->getName());
            }
        }

        // The engine records to the evidence chain itself; a chain that is not
        // configured makes this a verification run with no evidence, which is
        // reported rather than hidden so the operator can see that the interval
        // fired and produced nothing durable.
        $report = $this->engine->verify();
        $recorded = $this->latestRecord();
        $stored = $recorded !== null && ($last === null || $recorded->id !== $last->id);

        return JobResult::success($this->getName(), $startedAt, sprintf(
            'compliance verification: %d passed, %d failed, %d skipped; evidence record %s',
            $report->passCount(),
            $report->failCount(),
            $report->skipCount(),
            // Two reasons produce no record and this job cannot tell them apart
            // from here — the engine holds the chain, and swallows the refusal so
            // a failed write never discards a completed verification. Naming both
            // is honest; naming only the first was not, once
            // {@see UnverifiableEvidenceChainException} became a thing that
            // happens.
            $stored
                ? 'stored'
                : 'NOT stored (no evidence chain is configured, or the chain refused the '
                    . 'append — the engine logs which)',
        ));
    }

    private function latestRecord(): ?EvidenceRecord
    {
        $records = $this->store->forControl(EvidenceChain::CONTROL_ID);

        if ($records === []) {
            return null;
        }

        return $records[count($records) - 1];
    }
}
