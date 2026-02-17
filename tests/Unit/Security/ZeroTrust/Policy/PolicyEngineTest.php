<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Policy;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
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
use Pulsar\Security\ZeroTrust\Policy\PolicyRule;

#[CoversClass(PolicyEngine::class)]
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
        self::assertSame(PolicyDecision::Grant, $result->decision);
        self::assertSame([], $result->missingClaims);
    }

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
        self::assertSame(PolicyDecision::StepUp, $result->decision);
    }

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
        self::assertSame(PolicyDecision::Grant, $result->decision);
    }

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

        $readResult = $engine->evaluate(new ClaimSet(), '/public/page', 'read');
        $writeResult = $engine->evaluate(new ClaimSet(), '/public/page', 'write');
        $deleteResult = $engine->evaluate(new ClaimSet(), '/public/page', 'delete');

        self::assertTrue($readResult->isGranted());
        self::assertTrue($writeResult->isGranted());
        self::assertTrue($deleteResult->isGranted());
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

        $matchResult = $engine->evaluate(new ClaimSet(), '/admin/users/settings', 'read');
        $noMatchResult = $engine->evaluate(new ClaimSet(), '/admin/users/profile', 'read');

        self::assertTrue($matchResult->isGranted());
        self::assertTrue($noMatchResult->isDenied());
    }

    #[Test]
    public function multipleRulesForSameResource(): void
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

        // Only low-confidence claim — strict rule fails, fallback grants StepUp
        $claims = new ClaimSet([
            $this->makeClaim('device.registered', true, ClaimSource::DeviceSignal, 0.8),
        ]);

        $result = $engine->evaluate($claims, '/admin/users', 'write');

        self::assertTrue($result->requiresStepUp());
    }

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
    public function eventDispatchedOnEvaluation(): void
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
    public function auditLogWrittenOnEvaluation(): void
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
                        && $metadata['claim_count'] === 0;
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
                self::anything(),
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
    public function multipleRequirementsAllMustBeSatisfied(): void
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

        // Only one of two claims present
        $claims = new ClaimSet([
            $this->makeClaim('device.registered', true, ClaimSource::DeviceSignal, 0.9),
        ]);

        $result = $engine->evaluate($claims, '/secure/data', 'write');

        self::assertTrue($result->isDenied());
        self::assertCount(1, $result->missingClaims);
        self::assertSame('location.trusted', $result->missingClaims[0]->claimName);
    }

    #[Test]
    public function exactConfidenceThresholdSatisfiesRequirement(): void
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
    public function actionMismatchDoesNotMatchRule(): void
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
