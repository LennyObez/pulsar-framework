<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Event;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\Event\AnomalyDetectedEvent;
use Pulsar\Security\ZeroTrust\Event\PolicyDecisionEvent;
use Pulsar\Security\ZeroTrust\Event\SignalRetentionPolicyChangedEvent;
use Pulsar\Security\ZeroTrust\Event\StepUpAttemptedEvent;
use Pulsar\Security\ZeroTrust\Event\StepUpLockoutEvent;
use Pulsar\Security\ZeroTrust\Policy\PolicyDecision;
use Pulsar\Security\ZeroTrust\Policy\PolicyEvaluationResult;

#[CoversClass(AnomalyDetectedEvent::class)]
#[CoversClass(PolicyDecisionEvent::class)]
#[CoversClass(SignalRetentionPolicyChangedEvent::class)]
#[CoversClass(StepUpAttemptedEvent::class)]
#[CoversClass(StepUpLockoutEvent::class)]
final class ZeroTrustEventTest extends TestCase
{
    #[Test]
    public function anomalyDetectedEventStoresProperties(): void
    {
        $event = new AnomalyDetectedEvent(
            source: ClaimSource::LocationSignal,
            anomalyType: 'impossible_travel',
            description: 'Location changed from NYC to Tokyo in 10 minutes',
            identityId: 'user-42',
            sessionId: 'sess-abc',
            severity: 0.9,
            details: ['from' => 'NYC', 'to' => 'Tokyo'],
        );

        self::assertSame(ClaimSource::LocationSignal, $event->source);
        self::assertSame('impossible_travel', $event->anomalyType);
        self::assertStringContainsString('NYC to Tokyo', $event->description);
        self::assertSame('user-42', $event->identityId);
        self::assertSame('sess-abc', $event->sessionId);
        self::assertSame(0.9, $event->severity);
        self::assertSame('NYC', $event->details['from']);
    }

    #[Test]
    public function anomalyDetectedDefaultsToEmptyDetails(): void
    {
        $event = new AnomalyDetectedEvent(
            source: ClaimSource::DeviceSignal,
            anomalyType: 'fingerprint_mismatch',
            description: 'Device fingerprint changed',
            identityId: 'user-1',
            sessionId: 'sess-1',
            severity: 0.5,
        );

        self::assertSame([], $event->details);
    }

    #[Test]
    public function signalRetentionPolicyChangedStoresProperties(): void
    {
        $event = new SignalRetentionPolicyChangedEvent(
            source: ClaimSource::BehaviorSignal,
            previousRetentionSeconds: 86400,
            newRetentionSeconds: 604800,
            changedBy: 'admin-1',
            reason: 'Extended for compliance audit',
        );

        self::assertSame(ClaimSource::BehaviorSignal, $event->source);
        self::assertSame(86400, $event->previousRetentionSeconds);
        self::assertSame(604800, $event->newRetentionSeconds);
        self::assertSame('admin-1', $event->changedBy);
        self::assertSame('Extended for compliance audit', $event->reason);
    }

    #[Test]
    public function signalRetentionPolicyDefaultsToEmptyReason(): void
    {
        $event = new SignalRetentionPolicyChangedEvent(
            source: ClaimSource::NetworkSignal,
            previousRetentionSeconds: 3600,
            newRetentionSeconds: 7200,
            changedBy: 'system',
        );

        self::assertSame('', $event->reason);
    }

    #[Test]
    public function stepUpAttemptedStoresProperties(): void
    {
        $event = new StepUpAttemptedEvent(
            identityId: 'user-99',
            resource: '/admin/settings',
            attemptNumber: 2,
            success: false,
        );

        self::assertSame('user-99', $event->identityId);
        self::assertSame('/admin/settings', $event->resource);
        self::assertSame(2, $event->attemptNumber);
        self::assertFalse($event->success);
    }

    #[Test]
    public function stepUpAttemptedSuccessCase(): void
    {
        $event = new StepUpAttemptedEvent(
            identityId: 'user-10',
            resource: '/api/sensitive',
            attemptNumber: 1,
            success: true,
        );

        self::assertTrue($event->success);
        self::assertSame(1, $event->attemptNumber);
    }

    #[Test]
    public function stepUpLockoutStoresProperties(): void
    {
        $lockedUntil = new DateTimeImmutable('2026-03-15T12:00:00+00:00');
        $event = new StepUpLockoutEvent(
            identityId: 'user-locked',
            attemptCount: 5,
            lockedUntil: $lockedUntil,
        );

        self::assertSame('user-locked', $event->identityId);
        self::assertSame(5, $event->attemptCount);
        self::assertSame($lockedUntil, $event->lockedUntil);
    }

    // ── PolicyDecisionEvent ─────────────────────────────────────────────

    #[Test]
    public function policyDecisionEventStoresProperties(): void
    {
        $evalResult = new PolicyEvaluationResult(
            decision: PolicyDecision::Grant,
            matchedRules: [],
            missingClaims: [],
            claimSnapshot: new ClaimSet(),
            resource: '/admin/users',
            action: 'read',
        );

        $event = new PolicyDecisionEvent(
            result: $evalResult,
            identityId: 'user-42',
            sessionId: 'sess-xyz',
        );

        self::assertSame($evalResult, $event->result);
        self::assertSame('user-42', $event->identityId);
        self::assertSame('sess-xyz', $event->sessionId);
        self::assertSame(PolicyDecision::Grant, $event->result->decision);
    }

    #[Test]
    public function policyDecisionEventWithDenyResult(): void
    {
        $evalResult = new PolicyEvaluationResult(
            decision: PolicyDecision::Deny,
            matchedRules: [],
            missingClaims: [],
            claimSnapshot: new ClaimSet(),
            resource: '/api/sensitive',
            action: 'delete',
        );

        $event = new PolicyDecisionEvent(
            result: $evalResult,
            identityId: 'user-1',
            sessionId: 'sess-1',
        );

        self::assertTrue($event->result->isDenied());
        self::assertSame('/api/sensitive', $event->result->resource);
    }
}
