<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Policy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\Policy\PolicyDecision;
use Pulsar\Security\ZeroTrust\Policy\PolicyEvaluationResult;

final class PolicyEvaluationResultTest extends TestCase
{
    #[Test]
    public function is_granted_returns_true_for_grant_decision(): void
    {
        $result = new PolicyEvaluationResult(
            decision: PolicyDecision::Grant,
            matchedRules: [],
            missingClaims: [],
            claimSnapshot: new ClaimSet(),
            resource: '/admin',
            action: 'read',
        );

        self::assertTrue($result->isGranted());
        self::assertFalse($result->isDenied());
        self::assertFalse($result->requiresStepUp());
    }

    #[Test]
    public function is_denied_returns_true_for_deny_decision(): void
    {
        $result = new PolicyEvaluationResult(
            decision: PolicyDecision::Deny,
            matchedRules: [],
            missingClaims: [],
            claimSnapshot: new ClaimSet(),
            resource: '/admin',
            action: 'write',
        );

        self::assertTrue($result->isDenied());
        self::assertFalse($result->isGranted());
        self::assertFalse($result->requiresStepUp());
    }

    #[Test]
    public function requires_step_up_returns_true_for_step_up_decision(): void
    {
        $result = new PolicyEvaluationResult(
            decision: PolicyDecision::StepUp,
            matchedRules: [],
            missingClaims: [],
            claimSnapshot: new ClaimSet(),
            resource: '/admin',
            action: 'delete',
        );

        self::assertTrue($result->requiresStepUp());
        self::assertFalse($result->isGranted());
        self::assertFalse($result->isDenied());
    }

    #[Test]
    public function stores_resource_and_action(): void
    {
        $result = new PolicyEvaluationResult(
            decision: PolicyDecision::Grant,
            matchedRules: [],
            missingClaims: [],
            claimSnapshot: new ClaimSet(),
            resource: '/api/v1/users',
            action: 'list',
        );

        self::assertSame('/api/v1/users', $result->resource);
        self::assertSame('list', $result->action);
    }

    #[Test]
    public function stores_claim_snapshot(): void
    {
        $claims = new ClaimSet();

        $result = new PolicyEvaluationResult(
            decision: PolicyDecision::Deny,
            matchedRules: [],
            missingClaims: [],
            claimSnapshot: $claims,
            resource: '/res',
            action: 'act',
        );

        self::assertSame($claims, $result->claimSnapshot);
    }
}
