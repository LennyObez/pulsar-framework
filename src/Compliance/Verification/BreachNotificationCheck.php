<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;
use Pulsar\Api\Api;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Security\Incident\IncidentInterface;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\Incident\IncidentSeverity;

use function array_map;
use function array_slice;
use function count;
use function implode;
use function intdiv;
use function sprintf;

/**
 * Holds the deployment's incident register to the breach-notification deadline
 * the active compliance profile resolved.
 *
 * {@see ComplianceProfile::$breachNotificationHours} is the strictest deadline
 * across every enabled framework — GDPR Art 33 gives 72 hours, NIS2 Art 23 gives
 * 24, HIPAA §164.408 gives 60 days — and until this check existed it was computed
 * on every boot and read by nothing. A deadline nobody measures against is not a
 * deadline.
 *
 * What is measured: every incident in the register at or above
 * {@see IncidentSeverity::High} whose report timestamp is further in the past than
 * the deadline. The check does not, and must not, claim to know whether the
 * operator actually notified a supervisory authority — no such record exists in
 * this framework, and inventing a pass from its absence is the defect ADR-0041
 * describes. What it can say, and says, is which reportable incidents have now
 * passed the point at which the notification was due.
 *
 * The register itself is the other half of the control. Without one bound, the
 * profile is asking for a deadline on records the deployment does not keep, and
 * that is a FAIL rather than a skip: a missing incident register is a missing
 * control, not an unobservable one.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class BreachNotificationCheck implements ComplianceCheckInterface
{
    /**
     * How many recent incidents are inspected.
     *
     * Bounded because the register is an append-only file that a long-lived
     * deployment grows without limit, and this check runs at boot. Incidents are
     * returned most-recent-first, so a deployment with more than this many
     * high-severity incidents has a far louder problem than a missed deadline.
     */
    private const int INSPECTION_LIMIT = 200;

    /** How many overdue incidents are named in the failure message. */
    private const int NAMED_IN_MESSAGE = 5;

    private const int SECONDS_PER_HOUR = 3600;

    /**
     * @param bool $constrained Whether at least one ENABLED framework actually mandates a
     *        breach-notification deadline, from
     *        {@see \Pulsar\Compliance\ComplianceProfileResolver::constraints()}. The resolved
     *        profile always carries 72 hours as its baseline default, and measuring against
     *        that default would attribute a deadline to frameworks that never set one.
     * @param IncidentReporterInterface|null $register The bound register, or null when the
     *        deployment keeps none.
     * @param ClockInterface|null $clock Injected so the deadline arithmetic is testable;
     *        the system clock is used when none is given.
     */
    public function __construct(
        private ComplianceProfile $profile,
        private bool $constrained,
        private ?IncidentReporterInterface $register = null,
        private ?ClockInterface $clock = null,
    ) {}

    #[Override]
    public function id(): string
    {
        return 'incident.breach_notification_deadline';
    }

    #[Override]
    public function description(): string
    {
        return 'Reportable incidents that have passed the breach-notification deadline the '
            . 'active compliance profile requires.';
    }

    #[Override]
    public function domain(): ComplianceCheckDomain
    {
        return ComplianceCheckDomain::IncidentResponse;
    }

    #[Override]
    public function execute(): CheckResult
    {
        if (!$this->constrained) {
            return CheckResult::skip(
                $this->id(),
                'No enabled framework sets a breach-notification deadline, so the profile carries '
                    . 'only the resolver baseline and nothing is measured against it.',
                $this->domain(),
            );
        }

        $deadlineHours = $this->profile->breachNotificationHours;

        if ($this->register === null) {
            return CheckResult::fail(
                $this->id(),
                sprintf(
                    'The active profile requires notification within %d hours of a breach, and no '
                        . 'incident register is bound: this deployment records no breach at all, so '
                        . 'the deadline runs against nothing.',
                    $deadlineHours,
                ),
                $this->domain(),
                [
                    'Bind an IncidentReporterInterface. SecurityWiring binds the append-only '
                        . 'FileIncidentReporter automatically when observability audit logging is '
                        . 'enabled in config/observability.php.',
                ],
            );
        }

        $now = $this->clock?->now() ?? new DateTimeImmutable();
        $deadlineSeconds = $deadlineHours * self::SECONDS_PER_HOUR;
        $reportable = $this->register->recent(self::INSPECTION_LIMIT, IncidentSeverity::High);

        $overdue = [];

        foreach ($reportable as $incident) {
            if (($now->getTimestamp() - $incident->reportedAt()->getTimestamp()) > $deadlineSeconds) {
                $overdue[] = $incident;
            }
        }

        if ($overdue !== []) {
            return CheckResult::fail(
                $this->id(),
                sprintf(
                    '%d reportable incident(s) are past the %d-hour notification deadline the active '
                        . 'profile requires: %s.%s',
                    count($overdue),
                    $deadlineHours,
                    implode('; ', array_map(
                        fn(IncidentInterface $i): string => $this->describe($i, $now),
                        array_slice($overdue, 0, self::NAMED_IN_MESSAGE),
                    )),
                    count($overdue) > self::NAMED_IN_MESSAGE
                        ? sprintf(' (%d more not listed)', count($overdue) - self::NAMED_IN_MESSAGE)
                        : '',
                ),
                $this->domain(),
                [
                    sprintf(
                        'Notify the supervisory authority for each incident listed, then record the '
                            . 'notification. Frameworks imposing the deadline: %s.',
                        $this->frameworkList(),
                    ),
                    'This check reads report timestamps only; it cannot see whether a notification '
                        . 'was sent, so an incident already notified still appears here until it '
                        . 'ages out of the register.',
                ],
            );
        }

        return CheckResult::pass(
            $this->id(),
            sprintf(
                'No reportable incident has passed the %d-hour notification deadline: %d incident(s) '
                    . 'at or above high severity are on record and all are inside the window.',
                $deadlineHours,
                count($reportable),
            ),
            $this->domain(),
            [
                'deadline_hours: ' . $deadlineHours,
                'register: ' . $this->register::class,
                'reportable_incidents_inspected: ' . count($reportable),
                'frameworks: ' . $this->frameworkList(),
            ],
        );
    }

    private function describe(IncidentInterface $incident, DateTimeImmutable $now): string
    {
        return sprintf(
            '%s (%s, reported %dh ago)',
            $incident->id(),
            $incident->severity()->value,
            intdiv($now->getTimestamp() - $incident->reportedAt()->getTimestamp(), self::SECONDS_PER_HOUR),
        );
    }

    private function frameworkList(): string
    {
        return implode(', ', array_map(
            static fn(ComplianceFramework $f): string => $f->value,
            $this->profile->enabledFrameworks,
        ));
    }
}
