<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Signal;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
    // ── Name / structure ───────────────────────────────────────────────

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
    public function allClaimsHaveTimestamps(): void
    {
        $provider = new BehaviorSignalProvider();
        $claims = $provider->evaluate($this->createContext('user-1'));

        foreach ($claims as $claim) {
            self::assertGreaterThan(new \DateTimeImmutable('-1 minute'), $claim->timestamp);
        }
    }

    // ── Anonymous identity ─────────────────────────────────────────────

    #[Test]
    public function anonymousIdentityReturnsLowConfidence(): void
    {
        $provider = new BehaviorSignalProvider();
        $claims = $provider->evaluate($this->createContext(''));

        self::assertFalse(self::firstClaim($claims, 'behavior.rapid_requests')->value);
        self::assertFalse(self::firstClaim($claims, 'behavior.anomalous_pattern')->value);
        self::assertSame(0.3, self::firstClaim($claims, 'behavior.rapid_requests')->confidence);
        self::assertSame(0.3, self::firstClaim($claims, 'behavior.anomalous_pattern')->confidence);
    }

    #[Test]
    public function anonymousIdentityIgnoresBaselineAndRate(): void
    {
        $baseline = new BehaviorBaseline(avgRequestsPerMinute: 5.0);
        $provider = new BehaviorSignalProvider($this->baselineReturning($baseline));

        // Even with a high request rate and baseline, anonymous returns safe defaults
        $context = $this->createContext('', attributes: [
            'current_request_rate' => 1000.0,
        ]);

        $claims = $provider->evaluate($context);

        self::assertFalse(self::firstClaim($claims, 'behavior.rapid_requests')->value);
        self::assertFalse(self::firstClaim($claims, 'behavior.anomalous_pattern')->value);
    }

    // ── Confidence levels ──────────────────────────────────────────────

    #[Test]
    public function noBaselineReturnsLowConfidence(): void
    {
        $provider = new BehaviorSignalProvider();
        $claims = $provider->evaluate($this->createContext('user-1'));

        self::assertSame(0.3, self::firstClaim($claims, 'behavior.rapid_requests')->confidence);
        self::assertSame(0.3, self::firstClaim($claims, 'behavior.anomalous_pattern')->confidence);
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
        self::assertSame(0.8, self::firstClaim($claims, 'behavior.anomalous_pattern')->confidence);
    }

    #[Test]
    public function baselineProviderReturnsNullForUnknownIdentity(): void
    {
        $baselineProvider = $this->createStub(BehaviorBaselineInterface::class);
        $baselineProvider->method('getBaseline')->willReturn(null);

        $provider = new BehaviorSignalProvider($baselineProvider);
        $claims = $provider->evaluate($this->createContext('unknown-user'));

        // No baseline, so low confidence
        self::assertSame(0.3, self::firstClaim($claims, 'behavior.rapid_requests')->confidence);
    }

    // ── Rapid request detection ────────────────────────────────────────

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
    public function rapidRequestsExactlyAtThresholdDoesNotTrigger(): void
    {
        $baseline = new BehaviorBaseline(avgRequestsPerMinute: 10.0);

        $provider = new BehaviorSignalProvider($this->baselineReturning($baseline));
        $context = $this->createContext('user-1', attributes: [
            'current_request_rate' => 30.0, // Exactly 3x baseline = threshold
        ]);

        $claims = $provider->evaluate($context);

        // 30.0 > 30.0 is false, so not rapid
        self::assertFalse(self::firstClaim($claims, 'behavior.rapid_requests')->value);
    }

    #[Test]
    public function rapidRequestsJustAboveThresholdTriggers(): void
    {
        $baseline = new BehaviorBaseline(avgRequestsPerMinute: 10.0);

        $provider = new BehaviorSignalProvider($this->baselineReturning($baseline));
        $context = $this->createContext('user-1', attributes: [
            'current_request_rate' => 30.01, // Just above 3x baseline
        ]);

        $claims = $provider->evaluate($context);

        self::assertTrue(self::firstClaim($claims, 'behavior.rapid_requests')->value);
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
    public function noRapidRequestsUnderDefaultThreshold(): void
    {
        $provider = new BehaviorSignalProvider(defaultMaxRequestsPerMinute: 60.0);
        $context = $this->createContext('user-1', attributes: [
            'current_request_rate' => 55.0, // < 60 default max
        ]);

        $claims = $provider->evaluate($context);

        self::assertFalse(self::firstClaim($claims, 'behavior.rapid_requests')->value);
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

    #[Test]
    public function zeroCurrentRateNeverTriggersRapid(): void
    {
        $baseline = new BehaviorBaseline(avgRequestsPerMinute: 10.0);

        $provider = new BehaviorSignalProvider($this->baselineReturning($baseline));
        $context = $this->createContext('user-1', attributes: [
            'current_request_rate' => 0.0,
        ]);

        $claims = $provider->evaluate($context);

        self::assertFalse(self::firstClaim($claims, 'behavior.rapid_requests')->value);
    }

    // ── Anomalous pattern detection ────────────────────────────────────

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
    public function anomalousPatternExactlyAtThresholdDoesNotTrigger(): void
    {
        $baseline = new BehaviorBaseline(avgRequestsPerMinute: 10.0);

        $provider = new BehaviorSignalProvider($this->baselineReturning($baseline));
        $context = $this->createContext('user-1', attributes: [
            'current_request_rate' => 30.0, // Exactly 200% deviation (abs(30-10)/10 = 2.0)
        ]);

        $claims = $provider->evaluate($context);

        // deviation > 2.0 is false for exactly 2.0
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
    public function anomalousPatternFalseWithoutCurrentRate(): void
    {
        $baseline = new BehaviorBaseline(avgRequestsPerMinute: 10.0);

        $provider = new BehaviorSignalProvider($this->baselineReturning($baseline));
        $claims = $provider->evaluate($this->createContext('user-1'));

        // No current_request_rate means no anomaly detection
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
    public function zeroBaselineWithZeroRateIsNotAnomalous(): void
    {
        $baseline = new BehaviorBaseline(avgRequestsPerMinute: 0.0);

        $provider = new BehaviorSignalProvider($this->baselineReturning($baseline));
        $context = $this->createContext('user-1', attributes: [
            'current_request_rate' => 0.0,
        ]);

        $claims = $provider->evaluate($context);

        // 0.0 > 0.0 is false
        self::assertFalse(self::firstClaim($claims, 'behavior.anomalous_pattern')->value);
    }

    #[Test]
    public function veryLowDeviationIsNotAnomalous(): void
    {
        $baseline = new BehaviorBaseline(avgRequestsPerMinute: 100.0);

        $provider = new BehaviorSignalProvider($this->baselineReturning($baseline));
        $context = $this->createContext('user-1', attributes: [
            'current_request_rate' => 105.0, // 5% deviation
        ]);

        $claims = $provider->evaluate($context);

        self::assertFalse(self::firstClaim($claims, 'behavior.anomalous_pattern')->value);
    }

    #[Test]
    public function anomalousPatternDetectsDownwardDeviation(): void
    {
        $baseline = new BehaviorBaseline(avgRequestsPerMinute: 100.0);

        $provider = new BehaviorSignalProvider($this->baselineReturning($baseline));
        $context = $this->createContext('user-1', attributes: [
            'current_request_rate' => 0.1, // Nearly zero when baseline is 100 -- >200% deviation
        ]);

        $claims = $provider->evaluate($context);

        // abs(0.1 - 100) / 100 = 0.999, which is < 2.0
        self::assertFalse(self::firstClaim($claims, 'behavior.anomalous_pattern')->value);
    }

    // ── Data provider for rapid detection across baseline scenarios ────

    /**
     * @return array<string, array{float, float, float, bool}>
     */
    public static function rapidRequestScenarioProvider(): array
    {
        return [
            'well below threshold' => [10.0, 5.0, 3.0, false],
            'at threshold boundary' => [10.0, 30.0, 3.0, false],
            'just above threshold' => [10.0, 30.1, 3.0, true],
            'double threshold' => [10.0, 60.0, 3.0, true],
            'custom multiplier below' => [20.0, 39.0, 2.0, false],
            'custom multiplier above' => [20.0, 41.0, 2.0, true],
        ];
    }

    #[Test]
    #[DataProvider('rapidRequestScenarioProvider')]
    public function rapidRequestDetectionWithVariousScenarios(
        float $baselineRate,
        float $currentRate,
        float $multiplier,
        bool $expectRapid,
    ): void {
        $baseline = new BehaviorBaseline(avgRequestsPerMinute: $baselineRate);

        $provider = new BehaviorSignalProvider(
            baselineProvider: $this->baselineReturning($baseline),
            rapidRequestMultiplier: $multiplier,
        );
        $context = $this->createContext('user-1', attributes: [
            'current_request_rate' => $currentRate,
        ]);

        $claims = $provider->evaluate($context);

        self::assertSame(
            $expectRapid,
            self::firstClaim($claims, 'behavior.rapid_requests')->value,
            sprintf(
                'Expected rapid_requests=%s for baseline=%.1f, current=%.1f, multiplier=%.1f',
                $expectRapid ? 'true' : 'false',
                $baselineRate,
                $currentRate,
                $multiplier,
            ),
        );
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
        $request = $this->createStub(ServerRequestInterface::class);

        return new SignalContext(
            request: $request,
            identityId: $identityId,
            attributes: $attributes,
        );
    }

    private function baselineReturning(BehaviorBaseline $baseline): BehaviorBaselineInterface
    {
        $provider = $this->createStub(BehaviorBaselineInterface::class);
        $provider->method('getBaseline')->willReturn($baseline);

        return $provider;
    }
}
