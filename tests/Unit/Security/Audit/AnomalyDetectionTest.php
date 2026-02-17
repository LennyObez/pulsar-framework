<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Audit\AnomalyDetection;
use Pulsar\Security\Audit\AnomalyRule;
use Pulsar\Security\Audit\AuditEvent;

#[CoversClass(AnomalyDetection::class)]
final class AnomalyDetectionTest extends TestCase
{
    public function testFromRuleCreatesDetection(): void
    {
        $rule = new AnomalyRule(
            name: 'excessive-access',
            event: AuditEvent::DataAccess,
            threshold: 50,
            windowSeconds: 3600,
        );

        $before = microtime(true);
        $detection = AnomalyDetection::fromRule($rule, 'actor-42', 55);
        $after = microtime(true);

        self::assertSame($rule, $detection->rule);
        self::assertSame('actor-42', $detection->actor);
        self::assertSame(55, $detection->eventCount);
        self::assertGreaterThanOrEqual($before, $detection->detectedAt);
        self::assertLessThanOrEqual($after, $detection->detectedAt);
    }

    public function testDirectConstruction(): void
    {
        $rule = new AnomalyRule(
            name: 'test-rule',
            event: AuditEvent::Authentication,
            threshold: 5,
            windowSeconds: 60,
        );

        $detection = new AnomalyDetection(
            rule: $rule,
            actor: 'user-1',
            eventCount: 8,
            detectedAt: 1710504000.0,
        );

        self::assertSame('user-1', $detection->actor);
        self::assertSame(8, $detection->eventCount);
        self::assertSame(1710504000.0, $detection->detectedAt);
    }
}
