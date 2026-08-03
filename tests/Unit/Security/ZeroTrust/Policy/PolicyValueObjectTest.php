<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Policy;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\Policy\ClaimRequirement;
use Pulsar\Security\ZeroTrust\Policy\PolicyDecision;
use Pulsar\Security\ZeroTrust\Policy\PolicyRule;

#[CoversClass(ClaimRequirement::class)]
#[CoversClass(PolicyRule::class)]
final class PolicyValueObjectTest extends TestCase
{
    // ── ClaimRequirement ────────────────────────────────────────────────

    #[Test]
    public function claimRequirementStoresProperties(): void
    {
        $req = new ClaimRequirement(
            claimName: 'device.registered',
            minConfidence: 0.8,
            allowedSources: [ClaimSource::DeviceSignal],
        );

        self::assertSame('device.registered', $req->claimName);
        self::assertSame(0.8, $req->minConfidence);
        self::assertCount(1, $req->allowedSources);
        self::assertSame(ClaimSource::DeviceSignal, $req->allowedSources[0]);
    }

    #[Test]
    public function claimRequirementDefaults(): void
    {
        $req = new ClaimRequirement(claimName: 'ip.in_range');

        self::assertSame(0.0, $req->minConfidence);
        self::assertSame([], $req->allowedSources);
    }

    #[Test]
    public function claimRequirementRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('must not be empty');

        new ClaimRequirement(claimName: '');
    }

    #[Test]
    public function claimRequirementRejectsNegativeConfidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('between 0.0 and 1.0');

        new ClaimRequirement(claimName: 'test', minConfidence: -0.1);
    }

    #[Test]
    public function claimRequirementRejectsConfidenceAboveOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('between 0.0 and 1.0');

        new ClaimRequirement(claimName: 'test', minConfidence: 1.01);
    }

    #[Test]
    public function claimRequirementAcceptsBoundaryConfidences(): void
    {
        $zero = new ClaimRequirement(claimName: 'low', minConfidence: 0.0);
        $one = new ClaimRequirement(claimName: 'high', minConfidence: 1.0);

        self::assertSame(0.0, $zero->minConfidence);
        self::assertSame(1.0, $one->minConfidence);
    }

    // ── PolicyRule ──────────────────────────────────────────────────────

    #[Test]
    public function policyRuleStoresAllProperties(): void
    {
        $req = new ClaimRequirement(claimName: 'device.registered', minConfidence: 0.5);

        $rule = new PolicyRule(
            name: 'admin-write',
            resourcePattern: '/admin/*',
            action: 'write',
            requirements: [$req],
            onMatch: PolicyDecision::Grant,
            onNoMatch: PolicyDecision::StepUp,
            priority: 10,
        );

        self::assertSame('admin-write', $rule->name);
        self::assertSame('/admin/*', $rule->resourcePattern);
        self::assertSame('write', $rule->action);
        self::assertCount(1, $rule->requirements);
        self::assertSame($req, $rule->requirements[0]);
        self::assertSame(PolicyDecision::Grant, $rule->onMatch);
        self::assertSame(PolicyDecision::StepUp, $rule->onNoMatch);
        self::assertSame(10, $rule->priority);
    }

    #[Test]
    public function policyRuleDefaults(): void
    {
        $rule = new PolicyRule(
            name: 'default-test',
            resourcePattern: '/*',
            action: '*',
            requirements: [],
        );

        self::assertSame(PolicyDecision::Grant, $rule->onMatch);
        self::assertSame(PolicyDecision::Deny, $rule->onNoMatch);
        self::assertSame(0, $rule->priority);
    }

    #[Test]
    public function policyRuleWithMultipleRequirements(): void
    {
        $reqs = [
            new ClaimRequirement(claimName: 'device.registered'),
            new ClaimRequirement(claimName: 'ip.in_range', minConfidence: 0.7),
            new ClaimRequirement(
                claimName: 'behavior.normal',
                allowedSources: [ClaimSource::BehaviorSignal],
            ),
        ];

        $rule = new PolicyRule(
            name: 'strict-access',
            resourcePattern: '/api/sensitive/*',
            action: 'delete',
            requirements: $reqs,
        );

        self::assertCount(3, $rule->requirements);
        self::assertSame('ip.in_range', $rule->requirements[1]->claimName);
        self::assertSame(0.7, $rule->requirements[1]->minConfidence);
    }
}
