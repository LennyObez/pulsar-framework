<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Compliance\Verification\BreachNotificationCheck;
use Pulsar\Compliance\Verification\CheckStatus;
use Pulsar\Compliance\Verification\ComplianceCheckDomain;
use Pulsar\Security\Incident\IncidentSeverity;
use Pulsar\Security\Incident\InMemoryIncidentReporter;
use Pulsar\Testing\Clock\TestClock;

use function str_contains;

#[CoversClass(BreachNotificationCheck::class)]
final class BreachNotificationCheckTest extends TestCase
{
    private const int DEADLINE_HOURS = 72;

    #[Test]
    public function failsWhenAReportableIncidentHasPassedTheDeadline(): void
    {
        $register = new InMemoryIncidentReporter();
        $register->report(IncidentSeverity::Critical, 'Data exfiltration', 'Records left the estate');

        // 80 hours later: GDPR Art 33 gave 72.
        $result = new BreachNotificationCheck(
            profile: $this->profile(),
            constrained: true,
            register: $register,
            clock: new TestClock(new DateTimeImmutable('+80 hours')),
        )->execute();

        self::assertSame(CheckStatus::Fail, $result->status);
        self::assertSame('incident.breach_notification_deadline', $result->checkId);
        self::assertSame(ComplianceCheckDomain::IncidentResponse, $result->domain);
        self::assertTrue(str_contains($result->message, '72-hour'), $result->message);
    }

    #[Test]
    public function passesWhileEveryReportableIncidentIsStillInsideTheWindow(): void
    {
        $register = new InMemoryIncidentReporter();
        $register->report(IncidentSeverity::High, 'Suspicious access', 'Under investigation');

        $result = new BreachNotificationCheck(
            profile: $this->profile(),
            constrained: true,
            register: $register,
            clock: new TestClock(new DateTimeImmutable('+1 hour')),
        )->execute();

        self::assertSame(CheckStatus::Pass, $result->status);
    }

    #[Test]
    public function theDeadlineIsTheProfilesAndNotAConstantOfItsOwn(): void
    {
        // NIS2 gives 24 hours. An incident 30 hours old is inside a 72-hour
        // deadline and outside a 24-hour one; if the check ignored the profile
        // both runs would agree, which is exactly the defect.
        $register = new InMemoryIncidentReporter();
        $register->report(IncidentSeverity::Critical, 'Outage', 'Significant incident');

        $clock = new TestClock(new DateTimeImmutable('+30 hours'));

        $lenient = new BreachNotificationCheck($this->profile(72), true, $register, $clock)->execute();
        $strict = new BreachNotificationCheck($this->profile(24), true, $register, $clock)->execute();

        self::assertSame(CheckStatus::Pass, $lenient->status);
        self::assertSame(CheckStatus::Fail, $strict->status);
    }

    #[Test]
    public function ignoresIncidentsBelowReportableSeverity(): void
    {
        $register = new InMemoryIncidentReporter();
        $register->report(IncidentSeverity::Low, 'Noisy scanner', 'Blocked at the edge');

        $result = new BreachNotificationCheck(
            profile: $this->profile(),
            constrained: true,
            register: $register,
            clock: new TestClock(new DateTimeImmutable('+400 hours')),
        )->execute();

        self::assertSame(CheckStatus::Pass, $result->status);
    }

    #[Test]
    public function failsWhenNoRegisterExistsAtAll(): void
    {
        // A deadline over records the deployment does not keep is not a control.
        $result = new BreachNotificationCheck(
            profile: $this->profile(),
            constrained: true,
            register: null,
        )->execute();

        self::assertSame(CheckStatus::Fail, $result->status);
        self::assertTrue(str_contains($result->message, 'no incident register'), $result->message);
    }

    #[Test]
    public function saysNothingWhenNoEnabledFrameworkSetsADeadline(): void
    {
        $register = new InMemoryIncidentReporter();
        $register->report(IncidentSeverity::Critical, 'Old', 'Ancient');

        $result = new BreachNotificationCheck(
            profile: $this->profile(),
            constrained: false,
            register: $register,
            clock: new TestClock(new DateTimeImmutable('+1000 hours')),
        )->execute();

        self::assertSame(CheckStatus::Skip, $result->status);
    }

    #[Test]
    public function doesNotClaimThatNotificationHappened(): void
    {
        // The framework holds no record of a notification being sent, so the
        // failure says what it measured and what it cannot see.
        $register = new InMemoryIncidentReporter();
        $register->report(IncidentSeverity::Critical, 'Breach', 'Confirmed');

        $result = new BreachNotificationCheck(
            profile: $this->profile(),
            constrained: true,
            register: $register,
            clock: new TestClock(new DateTimeImmutable('+100 hours')),
        )->execute();

        $remediations = $result->remediations;

        self::assertNotSame([], $remediations);
        self::assertTrue(
            str_contains($remediations[1] ?? '', 'cannot see whether a notification'),
            'the check must disclose the limit of what it measured',
        );
    }

    private function profile(int $deadlineHours = self::DEADLINE_HOURS): ComplianceProfile
    {
        return new ComplianceProfile(
            enabledFrameworks: [ComplianceFramework::Gdpr],
            passwordMinLength: 8,
            sessionIdleTimeout: 900,
            breachNotificationHours: $deadlineHours,
            auditRetentionDays: 365,
            dataRetentionDays: 365,
            mfaRequirement: 'none',
            encryptionAtRest: false,
            encryptionInTransit: false,
            tamperEvidentAudit: false,
            explicitConsent: false,
            consentWithdrawal: false,
            individualNotification: true,
            breachRegister: true,
        );
    }
}
