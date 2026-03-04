<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Audit\AnomalyDetection;
use Pulsar\Security\Audit\AnomalyRule;
use Pulsar\Security\Audit\AuditAnomalyDetector;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\Incident\IncidentSeverity;

#[CoversClass(AuditAnomalyDetector::class)]
#[CoversClass(AnomalyDetection::class)]
#[CoversClass(AnomalyRule::class)]
final class AuditAnomalyDetectorTest extends TestCase
{
    public function testNoRulesProducesNoDetections(): void
    {
        $detector = new AuditAnomalyDetector();
        $result = $detector->record(AuditEvent::SecurityEvent, 'user-1');

        self::assertSame([], $result);
        self::assertSame([], $detector->detections());
    }

    public function testRuleFiringOnThresholdReached(): void
    {
        $detector = new AuditAnomalyDetector();
        $detector->addRule(new AnomalyRule(
            name: 'excessive_logins',
            event: AuditEvent::SecurityEvent,
            threshold: 3,
            windowSeconds: 60,
        ));

        // Below threshold
        $detector->record(AuditEvent::SecurityEvent, 'user-1');
        $detector->record(AuditEvent::SecurityEvent, 'user-1');
        self::assertSame([], $detector->detections());

        // Reaches threshold
        $triggered = $detector->record(AuditEvent::SecurityEvent, 'user-1');
        self::assertCount(1, $triggered);
        self::assertSame('excessive_logins', $triggered[0]->rule->name);
        self::assertSame('user-1', $triggered[0]->actor);
        self::assertSame(3, $triggered[0]->eventCount);
    }

    public function testRuleDoesNotFireForDifferentEventType(): void
    {
        $detector = new AuditAnomalyDetector();
        $detector->addRule(new AnomalyRule(
            name: 'security_events',
            event: AuditEvent::SecurityEvent,
            threshold: 2,
            windowSeconds: 60,
        ));

        $detector->record(AuditEvent::DataAccess, 'user-1');
        $detector->record(AuditEvent::DataAccess, 'user-1');
        $detector->record(AuditEvent::DataAccess, 'user-1');

        self::assertSame([], $detector->detections());
    }

    public function testDifferentActorsTrackedSeparately(): void
    {
        $detector = new AuditAnomalyDetector();
        $detector->addRule(new AnomalyRule(
            name: 'events_rule',
            event: AuditEvent::SecurityEvent,
            threshold: 3,
            windowSeconds: 60,
        ));

        $detector->record(AuditEvent::SecurityEvent, 'user-1');
        $detector->record(AuditEvent::SecurityEvent, 'user-1');
        $detector->record(AuditEvent::SecurityEvent, 'user-2');
        $detector->record(AuditEvent::SecurityEvent, 'user-2');

        self::assertSame([], $detector->detections());

        // Only user-1 hits threshold
        $triggered = $detector->record(AuditEvent::SecurityEvent, 'user-1');
        self::assertCount(1, $triggered);
        self::assertSame('user-1', $triggered[0]->actor);
    }

    public function testResetClearsState(): void
    {
        $detector = new AuditAnomalyDetector();
        $detector->addRule(new AnomalyRule(
            name: 'test',
            event: AuditEvent::SecurityEvent,
            threshold: 2,
            windowSeconds: 60,
        ));

        $detector->record(AuditEvent::SecurityEvent, 'user-1');
        $detector->record(AuditEvent::SecurityEvent, 'user-1');

        self::assertNotEmpty($detector->detections());

        $detector->reset();
        self::assertSame([], $detector->detections());

        // After reset, events are counted fresh
        $result = $detector->record(AuditEvent::SecurityEvent, 'user-1');
        self::assertSame([], $result);
    }

    public function testRulesAccessor(): void
    {
        $detector = new AuditAnomalyDetector();
        self::assertSame([], $detector->rules());

        $rule = new AnomalyRule('test', AuditEvent::SecurityEvent, 5, 120);
        $detector->addRule($rule);

        self::assertCount(1, $detector->rules());
        self::assertSame($rule, $detector->rules()[0]);
    }

    public function testIncidentReporterCalledOnDetection(): void
    {
        $reporter = $this->createMock(IncidentReporterInterface::class);
        $reporter->expects(self::once())
            ->method('report')
            ->with(
                self::equalTo(IncidentSeverity::High),
                self::stringContains('excessive_logins'),
                self::anything(),
                self::anything(),
                self::anything(),
            );

        $detector = new AuditAnomalyDetector($reporter);
        $detector->addRule(new AnomalyRule(
            name: 'excessive_logins',
            event: AuditEvent::SecurityEvent,
            threshold: 2,
            windowSeconds: 60,
        ));

        $detector->record(AuditEvent::SecurityEvent, 'user-1');
        $detector->record(AuditEvent::SecurityEvent, 'user-1');
    }

    public function testWindowClearsAfterFiring(): void
    {
        $detector = new AuditAnomalyDetector();
        $detector->addRule(new AnomalyRule(
            name: 'test',
            event: AuditEvent::SecurityEvent,
            threshold: 2,
            windowSeconds: 60,
        ));

        // First firing
        $detector->record(AuditEvent::SecurityEvent, 'user-1');
        $triggered = $detector->record(AuditEvent::SecurityEvent, 'user-1');
        self::assertCount(1, $triggered);

        // After firing, window resets — next event should not trigger
        $result = $detector->record(AuditEvent::SecurityEvent, 'user-1');
        self::assertSame([], $result);
    }

    public function testAnomalyDetectionFromRule(): void
    {
        $rule = new AnomalyRule('test_rule', AuditEvent::SecurityEvent, 5, 300);
        $detection = AnomalyDetection::fromRule($rule, 'actor-1', 7);

        self::assertSame($rule, $detection->rule);
        self::assertSame('actor-1', $detection->actor);
        self::assertSame(7, $detection->eventCount);
        self::assertGreaterThan(0.0, $detection->detectedAt);
    }

    public function testAnomalyRuleProperties(): void
    {
        $rule = new AnomalyRule(
            name: 'my_rule',
            event: AuditEvent::DataAccess,
            threshold: 10,
            windowSeconds: 600,
        );

        self::assertSame('my_rule', $rule->name);
        self::assertSame(AuditEvent::DataAccess, $rule->event);
        self::assertSame(10, $rule->threshold);
        self::assertSame(600, $rule->windowSeconds);
    }
}
