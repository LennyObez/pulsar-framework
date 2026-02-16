<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Signal;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Security\ZeroTrust\Claim\Claim;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\Signal\BehaviorBaseline;
use Pulsar\Security\ZeroTrust\Signal\BehaviorBaselineInterface;
use Pulsar\Security\ZeroTrust\Signal\Internal\BehaviorSignalProvider;
use Pulsar\Security\ZeroTrust\Signal\SignalContext;

use function sprintf;

#[CoversClass(BehaviorSignalProvider::class)]
final class BehaviorSignalProviderTest extends TestCase
{
    #[Test]
    public function nameReturnsBehavior(): void
    {
        $provider = new BehaviorSignalProvider();

        self::assertSame('behavior', $provider->name());
    }

    #[Test]
    public function producesTwoClaims(): void
    {
        $provider = new BehaviorSignalProvider();
        $claims = $provider->evaluate($this->createContext('user-1'));

        self::assertCount(2, $claims);
        self::assertTrue($claims->has('behavior.rapid_requests'));
        self::assertTrue($claims->has('behavior.anomalous_pattern'));
    }

    #[Test]
    public function allClaimsHaveBehaviorSource(): void
    {
        $provider = new BehaviorSignalProvider();
        $claims = $provider->evaluate($this->createContext('user-1'));

        foreach ($claims as $claim) {
            self::assertSame(ClaimSource::BehaviorSignal, $claim->source);
        }
    }

    #[Test]
    public function anonymousIdentityReturnsLowConfidence(): void
    {
        $provider = new BehaviorSignalProvider();
        $claims = $provider->evaluate($this->createContext(''));

        self::assertFalse(self::firstClaim($claims, 'behavior.rapid_requests')->value);
        self::assertFalse(self::firstClaim($claims, 'behavior.anomalous_pattern')->value);
        self::assertSame(0.3, self::firstClaim($claims, 'behavior.rapid_requests')->confidence);
    }

    #[Test]
    public function noBaselineReturnsLowConfidence(): void
    {
        $provider = new BehaviorSignalProvider();
        $claims = $provider->evaluate($this->createContext('user-1'));

        self::assertSame(0.3, self::firstClaim($claims, 'behavior.rapid_requests')->confidence);
    }

    #[Test]
    public function withBaselineReturnsHighConfidence(): void
    {
        $baseline = new BehaviorBaseline(
            avgRequestsPerMinute: 10.0,
            lastActivity: new DateTimeImmutable(),
        );

        $provider = new BehaviorSignalProvider($this->baselineReturning($baseline));
        $claims = $provider->evaluate($this->createContext('user-1'));

        self::assertSame(0.8, self::firstClaim($claims, 'behavior.rapid_requests')->confidence);
    }

    #[Test]
    public function detectsRapidRequestsAboveBaselineThreshold(): void
    {
        $baseline = new BehaviorBaseline(avgRequestsPerMinute: 10.0);

        $provider = new BehaviorSignalProvider($this->baselineReturning($baseline));
        $context = $this->createContext('user-1', attributes: [
            'current_request_rate' => 35.0, // 3.5x baseline (> 3x threshold)
        ]);

        $claims = $provider->evaluate($context);

        self::assertTrue(self::firstClaim($claims, 'behavior.rapid_requests')->value);
    }

    #[Test]
    public function normalRequestRateDoesNotTriggerRapidRequests(): void
    {
        $baseline = new BehaviorBaseline(avgRequestsPerMinute: 10.0);

        $provider = new BehaviorSignalProvider($this->baselineReturning($baseline));
        $context = $this->createContext('user-1', attributes: [
            'current_request_rate' => 15.0, // 1.5x baseline (< 3x threshold)
        ]);

        $claims = $provider->evaluate($context);

        self::assertFalse(self::firstClaim($claims, 'behavior.rapid_requests')->value);
    }

    #[Test]
    public function detectsRapidRequestsWithDefaultThresholdWhenNoBaseline(): void
    {
        $provider = new BehaviorSignalProvider(defaultMaxRequestsPerMinute: 50.0);
        $context = $this->createContext('user-1', attributes: [
            'current_request_rate' => 55.0, // > 50 default max
        ]);

        $claims = $provider->evaluate($context);

        self::assertTrue(self::firstClaim($claims, 'behavior.rapid_requests')->value);
    }

    #[Test]
    public function noRapidRequestsWithoutCurrentRate(): void
    {
        $baseline = new BehaviorBaseline(avgRequestsPerMinute: 10.0);

        $provider = new BehaviorSignalProvider($this->baselineReturning($baseline));
        $claims = $provider->evaluate($this->createContext('user-1'));

        self::assertFalse(self::firstClaim($claims, 'behavior.rapid_requests')->value);
    }

    #[Test]
    public function detectsAnomalousPatternWithHighDeviation(): void
    {
        $baseline = new BehaviorBaseline(avgRequestsPerMinute: 10.0);

        $provider = new BehaviorSignalProvider($this->baselineReturning($baseline));
        $context = $this->createContext('user-1', attributes: [
            'current_request_rate' => 31.0, // 210% deviation (> 200% threshold)
        ]);

        $claims = $provider->evaluate($context);

        self::assertTrue(self::firstClaim($claims, 'behavior.anomalous_pattern')->value);
    }

    #[Test]
    public function normalDeviationDoesNotTriggerAnomalous(): void
    {
        $baseline = new BehaviorBaseline(avgRequestsPerMinute: 10.0);

        $provider = new BehaviorSignalProvider($this->baselineReturning($baseline));
        $context = $this->createContext('user-1', attributes: [
            'current_request_rate' => 20.0, // 100% deviation (< 200% threshold)
        ]);

        $claims = $provider->evaluate($context);

        self::assertFalse(self::firstClaim($claims, 'behavior.anomalous_pattern')->value);
    }

    #[Test]
    public function anomalousPatternFalseWithoutBaseline(): void
    {
        $provider = new BehaviorSignalProvider();
        $context = $this->createContext('user-1', attributes: [
            'current_request_rate' => 100.0,
        ]);

        $claims = $provider->evaluate($context);

        self::assertFalse(self::firstClaim($claims, 'behavior.anomalous_pattern')->value);
    }

    #[Test]
    public function zeroBaselineDetectsAnyActivityAsAnomalous(): void
    {
        $baseline = new BehaviorBaseline(avgRequestsPerMinute: 0.0);

        $provider = new BehaviorSignalProvider($this->baselineReturning($baseline));
        $context = $this->createContext('user-1', attributes: [
            'current_request_rate' => 1.0,
        ]);

        $claims = $provider->evaluate($context);

        self::assertTrue(self::firstClaim($claims, 'behavior.anomalous_pattern')->value);
    }

    #[Test]
    public function customRapidRequestMultiplier(): void
    {
        $baseline = new BehaviorBaseline(avgRequestsPerMinute: 10.0);

        $provider = new BehaviorSignalProvider(
            baselineProvider: $this->baselineReturning($baseline),
            rapidRequestMultiplier: 2.0,
        );
        $context = $this->createContext('user-1', attributes: [
            'current_request_rate' => 25.0, // 2.5x baseline (> 2x custom threshold)
        ]);

        $claims = $provider->evaluate($context);

        self::assertTrue(self::firstClaim($claims, 'behavior.rapid_requests')->value);
    }

    private static function firstClaim(ClaimSet $claims, string $name): Claim
    {
        $claim = $claims->first($name);
        self::assertNotNull($claim, sprintf('Expected claim "%s" to exist', $name));

        return $claim;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createContext(string $identityId, array $attributes = []): SignalContext
    {
        $request = $this->createMock(ServerRequestInterface::class);

        return new SignalContext(
            request: $request,
            identityId: $identityId,
            attributes: $attributes,
        );
    }

    private function baselineReturning(BehaviorBaseline $baseline): BehaviorBaselineInterface
    {
        $provider = $this->createMock(BehaviorBaselineInterface::class);
        $provider->method('getBaseline')->willReturn($baseline);

        return $provider;
    }
}
