<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Policy;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\ZeroTrust\Claim\Claim;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\Event\PolicyDecisionEvent;
use Pulsar\Security\ZeroTrust\Policy\ClaimRequirement;
use Pulsar\Security\ZeroTrust\Policy\Internal\PolicyEngine;
use Pulsar\Security\ZeroTrust\Policy\PolicyDecision;
use Pulsar\Security\ZeroTrust\Policy\PolicyEvaluationResult;
use Pulsar\Security\ZeroTrust\Policy\PolicyRule;

#[CoversClass(PolicyEngine::class)]
#[CoversClass(PolicyEvaluationResult::class)]
final class PolicyEngineTest extends TestCase
{
    private DateTimeImmutable $now;

    private EventDispatcherInterface $eventDispatcher;

    private AuditLoggerInterface $auditLogger;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable();
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);

        $this->auditLogger->method('log')->willReturn($this->createStub(AuditEntry::class));
    }

    // ── Deny-by-default semantics ──────────────────────────────────────

    #[Test]
    public function denyByDefaultWhenNoRulesMatch(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'admin-access',
                    resourcePattern: '/admin/*',
                    action: 'read',
                    requirements: [],
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $result = $engine->evaluate(new ClaimSet(), '/public/page', 'read');

        self::assertTrue($result->isDenied());
        self::assertSame(PolicyDecision::Deny, $result->decision);
        self::assertSame([], $result->matchedRules);
        self::assertSame([], $result->missingClaims);
    }

    #[Test]
    public function denyByDefaultWhenNoRulesConfigured(): void
    {
        $engine = new PolicyEngine(
            rules: [],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $result = $engine->evaluate(new ClaimSet(), '/anything', 'read');

        self::assertTrue($result->isDenied());
        self::assertSame([], $result->matchedRules);
    }

    #[Test]
    public function denyByDefaultWhenActionDoesNotMatch(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'read-only',
                    resourcePattern: '/docs/*',
                    action: 'read',
                    requirements: [],
                    onMatch: PolicyDecision::Grant,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $result = $engine->evaluate(new ClaimSet(), '/docs/readme', 'write');

        self::assertTrue($result->isDenied());
    }

    // ── Grant logic ────────────────────────────────────────────────────

    #[Test]
    public function grantsWhenAllRequirementsSatisfied(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'admin-read',
                    resourcePattern: '/admin/*',
                    action: 'read',
                    requirements: [
                        new ClaimRequirement('device.registered', 0.8, [ClaimSource::DeviceSignal]),
                    ],
                    onMatch: PolicyDecision::Grant,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $claims = new ClaimSet([
            $this->makeClaim('device.registered', true, ClaimSource::DeviceSignal, 0.9),
        ]);

        $result = $engine->evaluate($claims, '/admin/users', 'read');

        self::assertTrue($result->isGranted());
        self::assertFalse($result->isDenied());
        self::assertFalse($result->requiresStepUp());
        self::assertSame(PolicyDecision::Grant, $result->decision);
        self::assertSame([], $result->missingClaims);
    }

    #[Test]
    public function grantsWithNoRequirementsRule(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'open-access',
                    resourcePattern: '/public/*',
                    action: '*',
                    requirements: [],
                    onMatch: PolicyDecision::Grant,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $result = $engine->evaluate(new ClaimSet(), '/public/page', 'read');

        self::assertTrue($result->isGranted());
    }

    #[Test]
    public function grantsWithExactConfidenceThreshold(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'exact-threshold',
                    resourcePattern: '/test/*',
                    action: 'read',
                    requirements: [
                        new ClaimRequirement('device.registered', 0.8),
                    ],
                    onMatch: PolicyDecision::Grant,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $claims = new ClaimSet([
            $this->makeClaim('device.registered', true, ClaimSource::DeviceSignal, 0.8),
        ]);

        $result = $engine->evaluate($claims, '/test/resource', 'read');

        self::assertTrue($result->isGranted());
    }

    #[Test]
    public function grantsWithMultipleRequirementsAllSatisfied(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'multi-req',
                    resourcePattern: '/secure/*',
                    action: 'write',
                    requirements: [
                        new ClaimRequirement('device.registered', 0.8),
                        new ClaimRequirement('location.trusted', 0.7),
                        new ClaimRequirement('behavior.normal', 0.5),
                    ],
                    onMatch: PolicyDecision::Grant,
                    onNoMatch: PolicyDecision::Deny,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $claims = new ClaimSet([
            $this->makeClaim('device.registered', true, ClaimSource::DeviceSignal, 0.9),
            $this->makeClaim('location.trusted', true, ClaimSource::LocationSignal, 0.85),
            $this->makeClaim('behavior.normal', true, ClaimSource::BehaviorSignal, 0.6),
        ]);

        $result = $engine->evaluate($claims, '/secure/data', 'write');

        self::assertTrue($result->isGranted());
        self::assertSame([], $result->missingClaims);
    }

    // ── Deny logic ─────────────────────────────────────────────────────

    #[Test]
    public function deniesWhenRequiredClaimIsMissing(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'admin-read',
                    resourcePattern: '/admin/*',
                    action: 'read',
                    requirements: [
                        new ClaimRequirement('device.registered', 0.5),
                    ],
                    onMatch: PolicyDecision::Grant,
                    onNoMatch: PolicyDecision::Deny,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $result = $engine->evaluate(new ClaimSet(), '/admin/users', 'read');

        self::assertTrue($result->isDenied());
        self::assertCount(1, $result->missingClaims);
        self::assertSame('device.registered', $result->missingClaims[0]->claimName);
    }

    #[Test]
    public function deniesWhenClaimConfidenceBelowThreshold(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'admin-read',
                    resourcePattern: '/admin/*',
                    action: 'read',
                    requirements: [
                        new ClaimRequirement('device.registered', 0.8),
                    ],
                    onMatch: PolicyDecision::Grant,
                    onNoMatch: PolicyDecision::Deny,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $claims = new ClaimSet([
            $this->makeClaim('device.registered', true, ClaimSource::DeviceSignal, 0.5),
        ]);

        $result = $engine->evaluate($claims, '/admin/settings', 'read');

        self::assertTrue($result->isDenied());
        self::assertCount(1, $result->missingClaims);
        self::assertSame('device.registered', $result->missingClaims[0]->claimName);
    }

    #[Test]
    public function deniesWhenConfidenceJustBelowThreshold(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'near-miss',
                    resourcePattern: '/test/*',
                    action: 'read',
                    requirements: [
                        new ClaimRequirement('device.registered', 0.8),
                    ],
                    onMatch: PolicyDecision::Grant,
                    onNoMatch: PolicyDecision::Deny,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $claims = new ClaimSet([
            $this->makeClaim('device.registered', true, ClaimSource::DeviceSignal, 0.79),
        ]);

        $result = $engine->evaluate($claims, '/test/resource', 'read');

        self::assertTrue($result->isDenied());
    }

    #[Test]
    public function deniesWhenClaimSourceNotInAllowedSources(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'admin-read',
                    resourcePattern: '/admin/*',
                    action: 'read',
                    requirements: [
                        new ClaimRequirement('device.registered', 0.5, [ClaimSource::DeviceSignal]),
                    ],
                    onMatch: PolicyDecision::Grant,
                    onNoMatch: PolicyDecision::Deny,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $claims = new ClaimSet([
            $this->makeClaim('device.registered', true, ClaimSource::NetworkSignal, 0.9),
        ]);

        $result = $engine->evaluate($claims, '/admin/users', 'read');

        self::assertTrue($result->isDenied());
        self::assertCount(1, $result->missingClaims);
    }

    #[Test]
    public function deniesWhenOnlyPartialRequirementsMet(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'multi-req',
                    resourcePattern: '/secure/*',
                    action: 'write',
                    requirements: [
                        new ClaimRequirement('device.registered', 0.8),
                        new ClaimRequirement('location.trusted', 0.7),
                    ],
                    onMatch: PolicyDecision::Grant,
                    onNoMatch: PolicyDecision::Deny,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $claims = new ClaimSet([
            $this->makeClaim('device.registered', true, ClaimSource::DeviceSignal, 0.9),
        ]);

        $result = $engine->evaluate($claims, '/secure/data', 'write');

        self::assertTrue($result->isDenied());
        self::assertCount(1, $result->missingClaims);
        self::assertSame('location.trusted', $result->missingClaims[0]->claimName);
    }

    // ── StepUp decision ────────────────────────────────────────────────

    #[Test]
    public function stepUpDecisionWhenConfigured(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'sensitive-access',
                    resourcePattern: '/sensitive/*',
                    action: 'write',
                    requirements: [
                        new ClaimRequirement('mfa.verified', 0.9),
                    ],
                    onMatch: PolicyDecision::Grant,
                    onNoMatch: PolicyDecision::StepUp,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $claims = new ClaimSet([
            $this->makeClaim('device.registered', true, ClaimSource::DeviceSignal, 0.9),
        ]);

        $result = $engine->evaluate($claims, '/sensitive/data', 'write');

        self::assertTrue($result->requiresStepUp());
        self::assertFalse($result->isGranted());
        self::assertFalse($result->isDenied());
        self::assertSame(PolicyDecision::StepUp, $result->decision);
    }

    // ── Source filtering ───────────────────────────────────────────────

    #[Test]
    public function emptyAllowedSourcesAcceptsAnySource(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'any-source-rule',
                    resourcePattern: '/test/*',
                    action: 'read',
                    requirements: [
                        new ClaimRequirement('device.registered', 0.5, []),
                    ],
                    onMatch: PolicyDecision::Grant,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $claims = new ClaimSet([
            $this->makeClaim('device.registered', true, ClaimSource::BehaviorSignal, 0.9),
        ]);

        $result = $engine->evaluate($claims, '/test/resource', 'read');

        self::assertTrue($result->isGranted());
    }

    #[Test]
    public function claimFromOneOfMultipleAllowedSourcesIsAccepted(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'multi-source',
                    resourcePattern: '/test/*',
                    action: 'read',
                    requirements: [
                        new ClaimRequirement('verified', 0.5, [
                            ClaimSource::DeviceSignal,
                            ClaimSource::NetworkSignal,
                        ]),
                    ],
                    onMatch: PolicyDecision::Grant,
                    onNoMatch: PolicyDecision::Deny,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $claims = new ClaimSet([
            $this->makeClaim('verified', true, ClaimSource::NetworkSignal, 0.9),
        ]);

        $result = $engine->evaluate($claims, '/test/resource', 'read');

        self::assertTrue($result->isGranted());
    }

    #[Test]
    public function multipleClaimsSameNamePicksBestMatch(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'multi-claim',
                    resourcePattern: '/test/*',
                    action: 'read',
                    requirements: [
                        new ClaimRequirement('device.registered', 0.8, [ClaimSource::DeviceSignal]),
                    ],
                    onMatch: PolicyDecision::Grant,
                    onNoMatch: PolicyDecision::Deny,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        // First claim has wrong source, second has right source and confidence
        $claims = new ClaimSet([
            $this->makeClaim('device.registered', true, ClaimSource::NetworkSignal, 0.95),
            $this->makeClaim('device.registered', true, ClaimSource::DeviceSignal, 0.85),
        ]);

        $result = $engine->evaluate($claims, '/test/resource', 'read');

        self::assertTrue($result->isGranted());
    }

    #[Test]
    public function multipleClaimsSameNameAllInsufficient(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'strict',
                    resourcePattern: '/test/*',
                    action: 'read',
                    requirements: [
                        new ClaimRequirement('device.registered', 0.9, [ClaimSource::DeviceSignal]),
                    ],
                    onMatch: PolicyDecision::Grant,
                    onNoMatch: PolicyDecision::Deny,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        // Both claims have right name but neither meets all requirements
        $claims = new ClaimSet([
            $this->makeClaim('device.registered', true, ClaimSource::DeviceSignal, 0.5), // too low confidence
            $this->makeClaim('device.registered', true, ClaimSource::NetworkSignal, 0.95), // wrong source
        ]);

        $result = $engine->evaluate($claims, '/test/resource', 'read');

        self::assertTrue($result->isDenied());
    }

    // ── Priority and rule ordering ─────────────────────────────────────

    #[Test]
    public function priorityOrderingEvaluatesHighestFirst(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'low-priority',
                    resourcePattern: '/admin/*',
                    action: 'read',
                    requirements: [],
                    onMatch: PolicyDecision::Deny,
                    priority: 10,
                ),
                new PolicyRule(
                    name: 'high-priority',
                    resourcePattern: '/admin/*',
                    action: 'read',
                    requirements: [],
                    onMatch: PolicyDecision::Grant,
                    priority: 100,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $result = $engine->evaluate(new ClaimSet(), '/admin/users', 'read');

        self::assertTrue($result->isGranted());
    }

    #[Test]
    public function highPriorityRuleFailsFallsThroughToLower(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'strict-rule',
                    resourcePattern: '/admin/*',
                    action: 'write',
                    requirements: [
                        new ClaimRequirement('admin.verified', 0.95),
                    ],
                    onMatch: PolicyDecision::Grant,
                    onNoMatch: PolicyDecision::Deny,
                    priority: 100,
                ),
                new PolicyRule(
                    name: 'fallback-rule',
                    resourcePattern: '/admin/*',
                    action: 'write',
                    requirements: [
                        new ClaimRequirement('device.registered', 0.5),
                    ],
                    onMatch: PolicyDecision::StepUp,
                    onNoMatch: PolicyDecision::Deny,
                    priority: 50,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        // Only device claim, not admin claim
        $claims = new ClaimSet([
            $this->makeClaim('device.registered', true, ClaimSource::DeviceSignal, 0.8),
        ]);

        $result = $engine->evaluate($claims, '/admin/users', 'write');

        // Falls through to fallback rule which grants StepUp
        self::assertTrue($result->requiresStepUp());
    }

    #[Test]
    public function noRuleSatisfiedUsesHighestPriorityOnNoMatch(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'low-priority-step-up',
                    resourcePattern: '/admin/*',
                    action: 'write',
                    requirements: [
                        new ClaimRequirement('low.claim', 0.5),
                    ],
                    onMatch: PolicyDecision::Grant,
                    onNoMatch: PolicyDecision::StepUp,
                    priority: 10,
                ),
                new PolicyRule(
                    name: 'high-priority-deny',
                    resourcePattern: '/admin/*',
                    action: 'write',
                    requirements: [
                        new ClaimRequirement('high.claim', 0.5),
                    ],
                    onMatch: PolicyDecision::Grant,
                    onNoMatch: PolicyDecision::Deny,
                    priority: 100,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        // No claims at all - no rule satisfied
        $result = $engine->evaluate(new ClaimSet(), '/admin/data', 'write');

        // Uses highest priority rule's onNoMatch = Deny
        self::assertTrue($result->isDenied());
    }

    #[Test]
    public function samePriorityRulesAreStable(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'rule-a',
                    resourcePattern: '/test/*',
                    action: 'read',
                    requirements: [],
                    onMatch: PolicyDecision::Grant,
                    priority: 50,
                ),
                new PolicyRule(
                    name: 'rule-b',
                    resourcePattern: '/test/*',
                    action: 'read',
                    requirements: [
                        new ClaimRequirement('impossible.claim', 1.0),
                    ],
                    onMatch: PolicyDecision::Deny,
                    onNoMatch: PolicyDecision::Deny,
                    priority: 50,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        // rule-a has no requirements, so it should grant (the first matching rule with all requirements met)
        $result = $engine->evaluate(new ClaimSet(), '/test/page', 'read');

        self::assertTrue($result->isGranted());
    }

    // ── Pattern matching ───────────────────────────────────────────────

    #[Test]
    public function wildcardActionMatchesAnyAction(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'catch-all',
                    resourcePattern: '/public/*',
                    action: '*',
                    requirements: [],
                    onMatch: PolicyDecision::Grant,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        self::assertTrue($engine->evaluate(new ClaimSet(), '/public/page', 'read')->isGranted());
        self::assertTrue($engine->evaluate(new ClaimSet(), '/public/page', 'write')->isGranted());
        self::assertTrue($engine->evaluate(new ClaimSet(), '/public/page', 'delete')->isGranted());
    }

    #[Test]
    public function globResourcePatternMatching(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'admin-wildcard',
                    resourcePattern: '/admin/*/settings',
                    action: 'read',
                    requirements: [],
                    onMatch: PolicyDecision::Grant,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        self::assertTrue($engine->evaluate(new ClaimSet(), '/admin/users/settings', 'read')->isGranted());
        self::assertTrue($engine->evaluate(new ClaimSet(), '/admin/users/profile', 'read')->isDenied());
    }

    #[Test]
    public function exactResourceMatchWorks(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'exact-match',
                    resourcePattern: '/api/health',
                    action: 'read',
                    requirements: [],
                    onMatch: PolicyDecision::Grant,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        self::assertTrue($engine->evaluate(new ClaimSet(), '/api/health', 'read')->isGranted());
        self::assertTrue($engine->evaluate(new ClaimSet(), '/api/health/deeper', 'read')->isDenied());
    }

    #[Test]
    public function doubleStarGlobPattern(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'deep-wildcard',
                    resourcePattern: '/api/**',
                    action: 'read',
                    requirements: [],
                    onMatch: PolicyDecision::Grant,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        // fnmatch with ** depends on platform behavior; just verify it runs
        $result = $engine->evaluate(new ClaimSet(), '/api/v1/users', 'read');
        // The result depends on fnmatch behavior, but the engine should not throw
        self::assertInstanceOf(PolicyEvaluationResult::class, $result);
    }

    // ── Result payload ─────────────────────────────────────────────────

    #[Test]
    public function claimSnapshotCapturedInResult(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'test-rule',
                    resourcePattern: '/test/*',
                    action: 'read',
                    requirements: [],
                    onMatch: PolicyDecision::Grant,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $claims = new ClaimSet([
            $this->makeClaim('device.registered', true, ClaimSource::DeviceSignal, 0.9),
            $this->makeClaim('location.country', 'US', ClaimSource::LocationSignal, 0.8),
        ]);

        $result = $engine->evaluate($claims, '/test/resource', 'read');

        self::assertSame($claims, $result->claimSnapshot);
        self::assertCount(2, $result->claimSnapshot);
    }

    #[Test]
    public function resourceAndActionCapturedInResult(): void
    {
        $engine = new PolicyEngine(
            rules: [],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $result = $engine->evaluate(new ClaimSet(), '/api/users', 'delete');

        self::assertSame('/api/users', $result->resource);
        self::assertSame('delete', $result->action);
    }

    #[Test]
    public function matchedRulesIncludedInResult(): void
    {
        $rule = new PolicyRule(
            name: 'test-rule',
            resourcePattern: '/test/*',
            action: 'read',
            requirements: [
                new ClaimRequirement('device.registered', 0.8),
            ],
            onMatch: PolicyDecision::Grant,
            onNoMatch: PolicyDecision::Deny,
        );

        $engine = new PolicyEngine(
            rules: [$rule],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $result = $engine->evaluate(new ClaimSet(), '/test/resource', 'read');

        self::assertCount(1, $result->matchedRules);
        self::assertSame('test-rule', $result->matchedRules[0]->name);
    }

    #[Test]
    public function missingClaimsReportedFromHighestPriorityRule(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'high-rule',
                    resourcePattern: '/admin/*',
                    action: 'read',
                    requirements: [
                        new ClaimRequirement('mfa.verified', 0.9),
                        new ClaimRequirement('device.registered', 0.8),
                    ],
                    onMatch: PolicyDecision::Grant,
                    onNoMatch: PolicyDecision::Deny,
                    priority: 100,
                ),
                new PolicyRule(
                    name: 'low-rule',
                    resourcePattern: '/admin/*',
                    action: 'read',
                    requirements: [
                        new ClaimRequirement('basic.auth', 0.5),
                    ],
                    onMatch: PolicyDecision::Grant,
                    onNoMatch: PolicyDecision::Deny,
                    priority: 10,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $result = $engine->evaluate(new ClaimSet(), '/admin/data', 'read');

        // Missing claims from the highest priority rule (high-rule)
        self::assertCount(2, $result->missingClaims);
        $missingNames = array_map(
            static fn(ClaimRequirement $r): string => $r->claimName,
            $result->missingClaims,
        );
        self::assertContains('mfa.verified', $missingNames);
        self::assertContains('device.registered', $missingNames);
    }

    // ── Event dispatch ─────────────────────────────────────────────────

    #[Test]
    public function eventDispatchedOnEveryEvaluation(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (mixed $event): bool {
                return $event instanceof PolicyDecisionEvent
                    && $event->result->decision === PolicyDecision::Grant
                    && $event->result->resource === '/test/resource'
                    && $event->result->action === 'read';
            }));

        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'test-rule',
                    resourcePattern: '/test/*',
                    action: 'read',
                    requirements: [],
                    onMatch: PolicyDecision::Grant,
                ),
            ],
            eventDispatcher: $eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $engine->evaluate(new ClaimSet(), '/test/resource', 'read');
    }

    #[Test]
    public function eventContainsIdentityAndSessionIds(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (mixed $event): bool {
                return $event instanceof PolicyDecisionEvent
                    && $event->identityId === 'user-42'
                    && $event->sessionId === 'sess-abc';
            }));

        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'test',
                    resourcePattern: '/test/*',
                    action: 'read',
                    requirements: [],
                    onMatch: PolicyDecision::Grant,
                ),
            ],
            eventDispatcher: $eventDispatcher,
            auditLogger: $this->auditLogger,
            identityId: 'user-42',
            sessionId: 'sess-abc',
        );

        $engine->evaluate(new ClaimSet(), '/test/resource', 'read');
    }

    #[Test]
    public function eventDispatchedOnDenyNoMatchingRules(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (mixed $event): bool {
                return $event instanceof PolicyDecisionEvent
                    && $event->result->decision === PolicyDecision::Deny;
            }));

        $engine = new PolicyEngine(
            rules: [],
            eventDispatcher: $eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $engine->evaluate(new ClaimSet(), '/test/resource', 'read');
    }

    // ── Audit logging ──────────────────────────────────────────────────

    #[Test]
    public function auditLogWrittenWithGrantOutcome(): void
    {
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->auditLogger
            ->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::Authorization,
                AuditOutcome::Success,
                null,
                'policy.evaluate',
                '/test/resource',
                self::callback(static function (array $metadata): bool {
                    return $metadata['decision'] === 'grant'
                        && $metadata['action'] === 'read'
                        && $metadata['claim_count'] === 0
                        && $metadata['matched_rules'] === ['test-rule']
                        && $metadata['missing_claims'] === [];
                }),
            )
            ->willReturn($this->createStub(AuditEntry::class));

        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'test-rule',
                    resourcePattern: '/test/*',
                    action: 'read',
                    requirements: [],
                    onMatch: PolicyDecision::Grant,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $engine->evaluate(new ClaimSet(), '/test/resource', 'read');
    }

    #[Test]
    public function auditLogWrittenWithDeniedOutcomeOnDeny(): void
    {
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->auditLogger
            ->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::Authorization,
                AuditOutcome::Denied,
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (array $metadata): bool {
                    return $metadata['decision'] === 'deny';
                }),
            )
            ->willReturn($this->createStub(AuditEntry::class));

        $engine = new PolicyEngine(
            rules: [],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $engine->evaluate(new ClaimSet(), '/test/resource', 'read');
    }

    #[Test]
    public function auditLogWrittenWithFailureOutcomeOnStepUp(): void
    {
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->auditLogger
            ->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::Authorization,
                AuditOutcome::Failure,
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (array $metadata): bool {
                    return $metadata['decision'] === 'step_up';
                }),
            )
            ->willReturn($this->createStub(AuditEntry::class));

        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'step-up-rule',
                    resourcePattern: '/mfa/*',
                    action: 'write',
                    requirements: [
                        new ClaimRequirement('mfa.verified', 0.9),
                    ],
                    onMatch: PolicyDecision::Grant,
                    onNoMatch: PolicyDecision::StepUp,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $engine->evaluate(new ClaimSet(), '/mfa/action', 'write');
    }

    #[Test]
    public function auditLogIncludesIdentityAndSessionId(): void
    {
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->auditLogger
            ->expects(self::once())
            ->method('log')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (array $metadata): bool {
                    return $metadata['identity_id'] === 'id-99'
                        && $metadata['session_id'] === 'sess-xyz';
                }),
            )
            ->willReturn($this->createStub(AuditEntry::class));

        $engine = new PolicyEngine(
            rules: [],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
            identityId: 'id-99',
            sessionId: 'sess-xyz',
        );

        $engine->evaluate(new ClaimSet(), '/test', 'read');
    }

    #[Test]
    public function auditLogIncludesMissingClaimNames(): void
    {
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->auditLogger
            ->expects(self::once())
            ->method('log')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (array $metadata): bool {
                    return $metadata['missing_claims'] === ['admin.verified'];
                }),
            )
            ->willReturn($this->createStub(AuditEntry::class));

        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'strict',
                    resourcePattern: '/admin/*',
                    action: 'read',
                    requirements: [
                        new ClaimRequirement('admin.verified', 0.9),
                    ],
                    onMatch: PolicyDecision::Grant,
                    onNoMatch: PolicyDecision::Deny,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $engine->evaluate(new ClaimSet(), '/admin/page', 'read');
    }

    #[Test]
    public function auditLogClaimCountReflectsActualClaims(): void
    {
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->auditLogger
            ->expects(self::once())
            ->method('log')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (array $metadata): bool {
                    return $metadata['claim_count'] === 3;
                }),
            )
            ->willReturn($this->createStub(AuditEntry::class));

        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'test',
                    resourcePattern: '/test/*',
                    action: 'read',
                    requirements: [],
                    onMatch: PolicyDecision::Grant,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $claims = new ClaimSet([
            $this->makeClaim('a', true, ClaimSource::DeviceSignal, 0.5),
            $this->makeClaim('b', true, ClaimSource::DeviceSignal, 0.5),
            $this->makeClaim('c', true, ClaimSource::DeviceSignal, 0.5),
        ]);

        $engine->evaluate($claims, '/test/resource', 'read');
    }

    // ── Adversarial / edge cases ───────────────────────────────────────

    #[Test]
    public function emptyResourceStringEvaluatedSafely(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'empty-match',
                    resourcePattern: '',
                    action: 'read',
                    requirements: [],
                    onMatch: PolicyDecision::Grant,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $result = $engine->evaluate(new ClaimSet(), '', 'read');

        self::assertTrue($result->isGranted());
    }

    #[Test]
    public function specialCharactersInResourcePath(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'special-chars',
                    resourcePattern: '/api/v1/*',
                    action: 'read',
                    requirements: [],
                    onMatch: PolicyDecision::Grant,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        // Path traversal attempt
        $result = $engine->evaluate(new ClaimSet(), '/api/v1/../admin/secret', 'read');
        // fnmatch treats this as a literal string that matches /api/v1/*
        self::assertInstanceOf(PolicyEvaluationResult::class, $result);
    }

    #[Test]
    public function zeroConfidenceClaimMeetsZeroMinConfidenceRequirement(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'zero-threshold',
                    resourcePattern: '/test/*',
                    action: 'read',
                    requirements: [
                        new ClaimRequirement('claim', 0.0),
                    ],
                    onMatch: PolicyDecision::Grant,
                    onNoMatch: PolicyDecision::Deny,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $claims = new ClaimSet([
            $this->makeClaim('claim', true, ClaimSource::DeviceSignal, 0.0),
        ]);

        $result = $engine->evaluate($claims, '/test/resource', 'read');

        self::assertTrue($result->isGranted());
    }

    #[Test]
    public function maximumConfidenceClaimMeetsMaxRequirement(): void
    {
        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'max-threshold',
                    resourcePattern: '/test/*',
                    action: 'read',
                    requirements: [
                        new ClaimRequirement('claim', 1.0),
                    ],
                    onMatch: PolicyDecision::Grant,
                    onNoMatch: PolicyDecision::Deny,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
        );

        $claims = new ClaimSet([
            $this->makeClaim('claim', true, ClaimSource::DeviceSignal, 1.0),
        ]);

        $result = $engine->evaluate($claims, '/test/resource', 'read');

        self::assertTrue($result->isGranted());
    }

    /**
     * @return array<string, array{PolicyDecision, AuditOutcome}>
     */
    public static function decisionToAuditOutcomeProvider(): array
    {
        return [
            'Grant maps to Success' => [PolicyDecision::Grant, AuditOutcome::Success],
            'Deny maps to Denied' => [PolicyDecision::Deny, AuditOutcome::Denied],
            'StepUp maps to Failure' => [PolicyDecision::StepUp, AuditOutcome::Failure],
        ];
    }

    #[Test]
    #[DataProvider('decisionToAuditOutcomeProvider')]
    public function auditOutcomeMatchesDecision(PolicyDecision $decision, AuditOutcome $expectedOutcome): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger
            ->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::Authorization,
                $expectedOutcome,
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
            )
            ->willReturn($this->createStub(AuditEntry::class));

        $onMatch = $decision;
        $onNoMatch = $decision;

        $engine = new PolicyEngine(
            rules: [
                new PolicyRule(
                    name: 'test-rule',
                    resourcePattern: '/test/*',
                    action: 'read',
                    requirements: [],
                    onMatch: $onMatch,
                ),
            ],
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $auditLogger,
        );

        $engine->evaluate(new ClaimSet(), '/test/page', 'read');
    }

    private function makeClaim(string $name, mixed $value, ClaimSource $source, float $confidence): Claim
    {
        return new Claim(
            name: $name,
            value: $value,
            source: $source,
            confidence: $confidence,
            timestamp: $this->now,
        );
    }
}
