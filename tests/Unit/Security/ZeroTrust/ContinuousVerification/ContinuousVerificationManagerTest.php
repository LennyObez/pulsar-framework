<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\ContinuousVerification;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Security\ZeroTrust\Claim\Claim;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\ContinuousVerification\Internal\ContinuousVerificationManager;
use Pulsar\Security\ZeroTrust\Policy\PolicyDecision;
use Pulsar\Security\ZeroTrust\Policy\PolicyEngineInterface;
use Pulsar\Security\ZeroTrust\Policy\PolicyEvaluationResult;
use Pulsar\Security\ZeroTrust\Signal\SignalContext;
use Pulsar\Security\ZeroTrust\Signal\SignalProviderInterface;

#[CoversClass(ContinuousVerificationManager::class)]
final class ContinuousVerificationManagerTest extends TestCase
{
    #[Test]
    public function needsReverificationOnFirstCheck(): void
    {
        $manager = $this->createManager(intervalSeconds: 300);

        self::assertTrue($manager->needsReverification('session-1'));
    }

    #[Test]
    public function doesNotNeedReverificationWithinInterval(): void
    {
        $manager = $this->createManager(intervalSeconds: 300);
        $context = $this->createContext('session-1');

        $manager->verify($context, '/admin', 'GET');

        $now = new DateTimeImmutable();
        self::assertFalse($manager->needsReverification('session-1', $now));
    }

    #[Test]
    public function needsReverificationAfterIntervalExpires(): void
    {
        $manager = $this->createManager(intervalSeconds: 1);
        $context = $this->createContext('session-1');

        $manager->verify($context, '/admin', 'GET');

        $future = new DateTimeImmutable('+2 seconds');
        self::assertTrue($manager->needsReverification('session-1', $future));
    }

    #[Test]
    public function verifyCollectsClaimsFromAllProviders(): void
    {
        $now = new DateTimeImmutable();
        $claim1 = new Claim('device.registered', true, ClaimSource::DeviceSignal, 0.9, $now);
        $claim2 = new Claim('ip.in_range', true, ClaimSource::NetworkSignal, 0.8, $now);

        $provider1 = $this->createStub(SignalProviderInterface::class);
        $provider1->method('evaluate')->willReturn(new ClaimSet([$claim1]));

        $provider2 = $this->createStub(SignalProviderInterface::class);
        $provider2->method('evaluate')->willReturn(new ClaimSet([$claim2]));

        $policyEngine = $this->createStub(PolicyEngineInterface::class);
        $policyEngine->method('evaluate')->willReturnCallback(
            static function (ClaimSet $claims, string $resource, string $action): PolicyEvaluationResult {
                return new PolicyEvaluationResult(
                    decision: PolicyDecision::Grant,
                    matchedRules: [],
                    missingClaims: [],
                    claimSnapshot: $claims,
                    resource: $resource,
                    action: $action,
                );
            },
        );

        $manager = new ContinuousVerificationManager([$provider1, $provider2], $policyEngine, 300);
        $context = $this->createContext('session-1');

        $result = $manager->verify($context, '/admin', 'GET');

        self::assertTrue($result->isGranted());
        self::assertCount(2, $result->claimSnapshot);
    }

    #[Test]
    public function verifyReturnsEvaluationResult(): void
    {
        $policyEngine = $this->createStub(PolicyEngineInterface::class);
        $policyEngine->method('evaluate')->willReturn(new PolicyEvaluationResult(
            decision: PolicyDecision::Deny,
            matchedRules: [],
            missingClaims: [],
            claimSnapshot: new ClaimSet(),
            resource: '/admin',
            action: 'GET',
        ));

        $manager = new ContinuousVerificationManager([], $policyEngine, 300);
        $context = $this->createContext('session-1');

        $result = $manager->verify($context, '/admin', 'GET');

        self::assertTrue($result->isDenied());
    }

    #[Test]
    public function clearSessionRemovesTrackingState(): void
    {
        $manager = $this->createManager(intervalSeconds: 9999);
        $context = $this->createContext('session-1');

        $manager->verify($context, '/admin', 'GET');
        self::assertFalse($manager->needsReverification('session-1'));

        $manager->clearSession('session-1');
        self::assertTrue($manager->needsReverification('session-1'));
    }

    #[Test]
    public function tracksSeparateSessionsIndependently(): void
    {
        $manager = $this->createManager(intervalSeconds: 9999);

        $context1 = $this->createContext('session-1');
        $context2 = $this->createContext('session-2');

        $manager->verify($context1, '/admin', 'GET');

        self::assertFalse($manager->needsReverification('session-1'));
        self::assertTrue($manager->needsReverification('session-2'));

        $manager->verify($context2, '/admin', 'GET');
        self::assertFalse($manager->needsReverification('session-2'));
    }

    private function createManager(int $intervalSeconds): ContinuousVerificationManager
    {
        $policyEngine = $this->createStub(PolicyEngineInterface::class);
        $policyEngine->method('evaluate')->willReturn(new PolicyEvaluationResult(
            decision: PolicyDecision::Grant,
            matchedRules: [],
            missingClaims: [],
            claimSnapshot: new ClaimSet(),
            resource: '/admin',
            action: 'GET',
        ));

        return new ContinuousVerificationManager([], $policyEngine, $intervalSeconds);
    }

    private function createContext(string $sessionId): SignalContext
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn('GET');

        return new SignalContext(
            request: $request,
            sessionId: $sessionId,
            identityId: 'user-1',
        );
    }
}
