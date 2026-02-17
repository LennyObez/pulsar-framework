<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Audit\AnomalyRule;
use Pulsar\Security\Audit\AuditEvent;

#[CoversClass(AnomalyRule::class)]
final class AnomalyRuleTest extends TestCase
{
    public function testConstructorAssignsProperties(): void
    {
        $rule = new AnomalyRule(
            name: 'brute-force-login',
            event: AuditEvent::Authentication,
            threshold: 10,
            windowSeconds: 300,
        );

        self::assertSame('brute-force-login', $rule->name);
        self::assertSame(AuditEvent::Authentication, $rule->event);
        self::assertSame(10, $rule->threshold);
        self::assertSame(300, $rule->windowSeconds);
    }
}
