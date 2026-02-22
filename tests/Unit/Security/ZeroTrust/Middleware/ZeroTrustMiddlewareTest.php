<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Middleware;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub as StubInterface;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Config\ZeroTrustConfig;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Http\Factory\ResponseFactory;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\ZeroTrust\Claim\Claim;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\Event\PolicyDecisionEvent;
use Pulsar\Security\ZeroTrust\Middleware\ZeroTrustMiddleware;
use Pulsar\Security\ZeroTrust\Policy\ClaimRequirement;
use Pulsar\Security\ZeroTrust\Policy\PolicyDecision;
use Pulsar\Security\ZeroTrust\Policy\PolicyEngineInterface;
use Pulsar\Security\ZeroTrust\Policy\PolicyEvaluationResult;
use Pulsar\Security\ZeroTrust\Policy\PolicyRule;
use Pulsar\Security\ZeroTrust\Signal\SignalProviderInterface;
use Pulsar\Security\ZeroTrust\StepUp\Internal\StepUpManager;

#[CoversClass(ZeroTrustMiddleware::class)]
final class ZeroTrustMiddlewareTest extends TestCase
{
    private EventDispatcherInterface $eventDispatcher;
    private AuditLoggerInterface $auditLogger;
    private PolicyEngineInterface&StubInterface $policyEngine;
    private RequestHandlerInterface $handler;
    private ServerRequestInterface $request;
    private ResponseInterface $successResponse;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnCallback(
            static fn(object $event): object => $event,
        );

        $auditEntry = $this->createStub(AuditEntry::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);
        $this->auditLogger->method('log')->willReturn($auditEntry);

        $this->policyEngine = $this->createStub(PolicyEngineInterface::class);

        $this->successResponse = new ResponseFactory()->createResponse(200, 'OK');
        $this->handler = $this->createStub(RequestHandlerInterface::class);
        $this->handler->method('handle')->willReturn($this->successResponse);

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/users');

        $this->request = $this->createStub(ServerRequestInterface::class);
        $this->request->method('getUri')->willReturn($uri);
        $this->request->method('getMethod')->willReturn('GET');
        $this->request->method('getAttribute')->willReturnMap([
            ['session_id', '', 'sess-123'],
            ['identity_id', '', 'user-456'],
        ]);
    }

    #[Test]
    public function passesThroughWhenDisabled(): void
    {
        $config = new ZeroTrustConfig(enabled: false);
        $middleware = $this->buildMiddleware($config);

        $response = $middleware->process($this->request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function passesThroughOnGrant(): void
    {
        $this->policyEngine->method('evaluate')->willReturn($this->grantResult());

        $middleware = $this->buildMiddleware();
        $response = $middleware->process($this->request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function returnsForbiddenOnDeny(): void
    {
        $this->policyEngine->method('evaluate')->willReturn($this->denyResult());

        $middleware = $this->buildMiddleware();
        $response = $middleware->process($this->request, $this->handler);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function delegatesToStepUpManagerOnStepUpDecision(): void
    {
        $stepUpResult = new PolicyEvaluationResult(
            decision: PolicyDecision::StepUp,
            matchedRules: [
                new PolicyRule(
                    name: 'admin_mfa',
                    resourcePattern: '/admin/*',
                    action: '*',
                    requirements: [new ClaimRequirement('mfa.verified', 0.9)],
                ),
            ],
            missingClaims: [new ClaimRequirement('mfa.verified', 0.9)],
            claimSnapshot: new ClaimSet(),
            resource: '/admin/users',
            action: 'GET',
        );

        $this->policyEngine->method('evaluate')->willReturn($stepUpResult);

        $psrDispatcher = $this->createStub(PsrEventDispatcherInterface::class);
        $psrDispatcher->method('dispatch')->willReturnCallback(
            static fn(object $event): object => $event,
        );
        $stepUpManager = new StepUpManager($psrDispatcher);

        $middleware = $this->buildMiddleware(stepUpManager: $stepUpManager);
        $response = $middleware->process($this->request, $this->handler);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function collectsClaimsFromAllProviders(): void
    {
        $now = new DateTimeImmutable();
        $claim1 = new Claim('device.registered', true, ClaimSource::DeviceSignal, 0.9, $now);
        $claim2 = new Claim('ip.in_range', true, ClaimSource::NetworkSignal, 0.8, $now);

        $provider1 = $this->createStub(SignalProviderInterface::class);
        $provider1->method('evaluate')->willReturn(new ClaimSet([$claim1]));

        $provider2 = $this->createStub(SignalProviderInterface::class);
        $provider2->method('evaluate')->willReturn(new ClaimSet([$claim2]));

        $capturedClaims = null;
        $policyEngine = $this->createStub(PolicyEngineInterface::class);
        $policyEngine->method('evaluate')->willReturnCallback(
            static function (ClaimSet $claims) use (&$capturedClaims): PolicyEvaluationResult {
                $capturedClaims = $claims;

                return new PolicyEvaluationResult(
                    decision: PolicyDecision::Grant,
                    matchedRules: [],
                    missingClaims: [],
                    claimSnapshot: $claims,
                    resource: '/admin/users',
                    action: 'GET',
                );
            },
        );

        $config = new ZeroTrustConfig(enabled: true);
        $psrDispatcher = $this->createStub(PsrEventDispatcherInterface::class);
        $psrDispatcher->method('dispatch')->willReturnCallback(
            static fn(object $event): object => $event,
        );
        $stepUpManager = new StepUpManager($psrDispatcher);

        $middleware = new ZeroTrustMiddleware(
            config: $config,
            signalProviders: [$provider1, $provider2],
            policyEngine: $policyEngine,
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
            stepUpManager: $stepUpManager,
        );

        $middleware->process($this->request, $this->handler);

        self::assertNotNull($capturedClaims);
        self::assertCount(2, $capturedClaims);
        self::assertTrue($capturedClaims->has('device.registered'));
        self::assertTrue($capturedClaims->has('ip.in_range'));
    }

    #[Test]
    public function dispatchesPolicyDecisionEvent(): void
    {
        $dispatchedEvents = [];
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(
            static function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            },
        );

        $this->policyEngine->method('evaluate')->willReturn($this->grantResult());

        $psrDispatcher = $this->createStub(PsrEventDispatcherInterface::class);
        $psrDispatcher->method('dispatch')->willReturnCallback(
            static fn(object $event): object => $event,
        );
        $stepUpManager = new StepUpManager($psrDispatcher);

        $middleware = new ZeroTrustMiddleware(
            config: new ZeroTrustConfig(enabled: true),
            signalProviders: [],
            policyEngine: $this->policyEngine,
            eventDispatcher: $eventDispatcher,
            auditLogger: $this->auditLogger,
            stepUpManager: $stepUpManager,
        );

        $middleware->process($this->request, $this->handler);

        $policyEvents = array_filter(
            $dispatchedEvents,
            static fn(object $e): bool => $e instanceof PolicyDecisionEvent,
        );

        self::assertCount(1, $policyEvents);
        $event = array_values($policyEvents)[0];
        self::assertInstanceOf(PolicyDecisionEvent::class, $event);
        self::assertSame('user-456', $event->identityId);
        self::assertSame('sess-123', $event->sessionId);
    }

    #[Test]
    public function logsGrantDecision(): void
    {
        $this->policyEngine->method('evaluate')->willReturn($this->grantResult());

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('log')->with(
            AuditEvent::Authorization,
            AuditOutcome::Success,
            'user-456',
            'zero_trust.grant',
            '/admin/users',
        );

        $middleware = $this->buildMiddleware(auditLogger: $auditLogger);
        $middleware->process($this->request, $this->handler);
    }

    #[Test]
    public function logsDenyDecision(): void
    {
        $this->policyEngine->method('evaluate')->willReturn($this->denyResult());

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('log')->with(
            AuditEvent::Authorization,
            AuditOutcome::Denied,
            'user-456',
            'zero_trust.deny',
            '/admin/users',
        );

        $middleware = $this->buildMiddleware(auditLogger: $auditLogger);
        $middleware->process($this->request, $this->handler);
    }

    private function buildMiddleware(
        ?ZeroTrustConfig $config = null,
        ?StepUpManager $stepUpManager = null,
        ?AuditLoggerInterface $auditLogger = null,
    ): ZeroTrustMiddleware {
        if ($stepUpManager === null) {
            $psrDispatcher = $this->createStub(PsrEventDispatcherInterface::class);
            $psrDispatcher->method('dispatch')->willReturnCallback(
                static fn(object $event): object => $event,
            );
            $stepUpManager = new StepUpManager($psrDispatcher);
        }

        return new ZeroTrustMiddleware(
            config: $config ?? new ZeroTrustConfig(enabled: true),
            signalProviders: [],
            policyEngine: $this->policyEngine,
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $auditLogger ?? $this->auditLogger,
            stepUpManager: $stepUpManager,
        );
    }

    private function grantResult(): PolicyEvaluationResult
    {
        return new PolicyEvaluationResult(
            decision: PolicyDecision::Grant,
            matchedRules: [],
            missingClaims: [],
            claimSnapshot: new ClaimSet(),
            resource: '/admin/users',
            action: 'GET',
        );
    }

    private function denyResult(): PolicyEvaluationResult
    {
        return new PolicyEvaluationResult(
            decision: PolicyDecision::Deny,
            matchedRules: [],
            missingClaims: [],
            claimSnapshot: new ClaimSet(),
            resource: '/admin/users',
            action: 'GET',
        );
    }
}
