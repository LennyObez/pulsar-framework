<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ZeroTrustConfig;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\Privacy\SignalRetentionPolicy;
use Pulsar\Security\ZeroTrust\StepUp\StepUpConfig;

#[CoversClass(ZeroTrustConfig::class)]
final class ZeroTrustConfigTest extends TestCase
{
    #[Test]
    public function constructorDefaults(): void
    {
        $config = new ZeroTrustConfig();

        self::assertFalse($config->enabled);
        self::assertSame(0.7, $config->defaultMinConfidence);
        self::assertSame(300, $config->continuousVerificationIntervalSeconds);
        self::assertFalse($config->deviceIdentityRequired);
        self::assertInstanceOf(StepUpConfig::class, $config->stepUp);
        self::assertSame([], $config->retentionPolicies);
        self::assertSame([], $config->signalProviders);
        self::assertSame(0.6, $config->trustScoreThreshold);
    }

    #[Test]
    public function constructorWithCustomValues(): void
    {
        $stepUp = new StepUpConfig(maxAttempts: 3, cooldownSeconds: 10, lockoutSeconds: 600, windowSeconds: 1800);
        $config = new ZeroTrustConfig(
            enabled: true,
            defaultMinConfidence: 0.9,
            continuousVerificationIntervalSeconds: 120,
            deviceIdentityRequired: true,
            stepUp: $stepUp,
            retentionPolicies: [],
            signalProviders: ['App\\Signals\\GeoProvider'],
            trustScoreThreshold: 0.8,
        );

        self::assertTrue($config->enabled);
        self::assertSame(0.9, $config->defaultMinConfidence);
        self::assertSame(120, $config->continuousVerificationIntervalSeconds);
        self::assertTrue($config->deviceIdentityRequired);
        self::assertSame($stepUp, $config->stepUp);
        self::assertSame(['App\\Signals\\GeoProvider'], $config->signalProviders);
        self::assertSame(0.8, $config->trustScoreThreshold);
    }

    #[Test]
    public function fromArrayWithEmptyArrayReturnsDefaults(): void
    {
        $config = ZeroTrustConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame(0.7, $config->defaultMinConfidence);
        self::assertSame(300, $config->continuousVerificationIntervalSeconds);
        self::assertFalse($config->deviceIdentityRequired);
        self::assertSame([], $config->retentionPolicies);
        self::assertSame([], $config->signalProviders);
        self::assertSame(0.6, $config->trustScoreThreshold);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = ZeroTrustConfig::fromArray([
            'enabled' => true,
            'default_min_confidence' => 0.85,
            'continuous_verification_interval_seconds' => 60,
            'device_identity_required' => true,
            'step_up' => [
                'max_attempts' => 3,
                'cooldown_seconds' => 5,
                'lockout_seconds' => 1200,
                'window_seconds' => 7200,
            ],
            'retention_policies' => [
                [
                    'source' => 'device_signal',
                    'retention_seconds' => 86400,
                    'pseudonymize' => true,
                    'legal_basis' => 'GDPR Art. 6(1)(f)',
                ],
            ],
            'signal_providers' => [
                'App\\Provider\\DeviceProvider',
                'App\\Provider\\LocationProvider',
            ],
            'trust_score_threshold' => 0.75,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(0.85, $config->defaultMinConfidence);
        self::assertSame(60, $config->continuousVerificationIntervalSeconds);
        self::assertTrue($config->deviceIdentityRequired);
        self::assertSame(3, $config->stepUp->maxAttempts);
        self::assertSame(5, $config->stepUp->cooldownSeconds);
        self::assertSame(1200, $config->stepUp->lockoutSeconds);
        self::assertSame(7200, $config->stepUp->windowSeconds);
        self::assertCount(1, $config->retentionPolicies);
        self::assertInstanceOf(SignalRetentionPolicy::class, $config->retentionPolicies[0]);
        self::assertSame(ClaimSource::DeviceSignal, $config->retentionPolicies[0]->source);
        self::assertSame(86400, $config->retentionPolicies[0]->retentionSeconds);
        self::assertTrue($config->retentionPolicies[0]->pseudonymize);
        self::assertSame('GDPR Art. 6(1)(f)', $config->retentionPolicies[0]->legalBasis);
        self::assertSame(['App\\Provider\\DeviceProvider', 'App\\Provider\\LocationProvider'], $config->signalProviders);
        self::assertSame(0.75, $config->trustScoreThreshold);
    }

    #[Test]
    public function fromArrayCoercesIntegerConfidenceToFloat(): void
    {
        $config = ZeroTrustConfig::fromArray([
            'default_min_confidence' => 1,
            'trust_score_threshold' => 0,
        ]);

        self::assertSame(1.0, $config->defaultMinConfidence);
        self::assertSame(0.0, $config->trustScoreThreshold);
    }

    #[Test]
    public function fromArrayFallsBackOnNonNumericConfidence(): void
    {
        $config = ZeroTrustConfig::fromArray([
            'default_min_confidence' => 'high',
            'trust_score_threshold' => [1, 2, 3],
        ]);

        self::assertSame(0.7, $config->defaultMinConfidence);
        self::assertSame(0.6, $config->trustScoreThreshold);
    }

    #[Test]
    public function fromArrayFallsBackOnNonBoolEnabled(): void
    {
        $config = ZeroTrustConfig::fromArray([
            'enabled' => 'yes',
            'device_identity_required' => 1,
        ]);

        self::assertFalse($config->enabled);
        self::assertFalse($config->deviceIdentityRequired);
    }

    #[Test]
    public function fromArrayFallsBackOnNonIntInterval(): void
    {
        $config = ZeroTrustConfig::fromArray([
            'continuous_verification_interval_seconds' => '120',
        ]);

        self::assertSame(300, $config->continuousVerificationIntervalSeconds);
    }

    #[Test]
    public function fromArrayIgnoresNonArrayStepUp(): void
    {
        $config = ZeroTrustConfig::fromArray([
            'step_up' => 'invalid',
        ]);

        self::assertSame(5, $config->stepUp->maxAttempts);
        self::assertSame(0, $config->stepUp->cooldownSeconds);
        self::assertSame(900, $config->stepUp->lockoutSeconds);
        self::assertSame(3600, $config->stepUp->windowSeconds);
    }

    #[Test]
    public function fromArrayIgnoresNonArrayRetentionPolicies(): void
    {
        $config = ZeroTrustConfig::fromArray([
            'retention_policies' => 'not_an_array',
        ]);

        self::assertSame([], $config->retentionPolicies);
    }

    #[Test]
    public function fromArrayFiltersNonArrayItemsFromRetentionPolicies(): void
    {
        $config = ZeroTrustConfig::fromArray([
            'retention_policies' => [
                ['source' => 'device_signal', 'retention_seconds' => 3600],
                'not_an_array',
                42,
                ['source' => 'location_signal', 'retention_seconds' => 7200],
            ],
        ]);

        self::assertCount(2, $config->retentionPolicies);
        self::assertSame(ClaimSource::DeviceSignal, $config->retentionPolicies[0]->source);
        self::assertSame(ClaimSource::LocationSignal, $config->retentionPolicies[1]->source);
    }

    #[Test]
    public function fromArrayFiltersNonStringSignalProviders(): void
    {
        $config = ZeroTrustConfig::fromArray([
            'signal_providers' => [
                'App\\Provider\\Valid',
                42,
                null,
                true,
                'App\\Provider\\AlsoValid',
            ],
        ]);

        self::assertSame(['App\\Provider\\Valid', 'App\\Provider\\AlsoValid'], $config->signalProviders);
    }

    #[Test]
    public function fromArrayIgnoresNonArraySignalProviders(): void
    {
        $config = ZeroTrustConfig::fromArray([
            'signal_providers' => 'single_string',
        ]);

        self::assertSame([], $config->signalProviders);
    }

    #[Test]
    public function fromArrayWithNullValues(): void
    {
        $config = ZeroTrustConfig::fromArray([
            'enabled' => null,
            'default_min_confidence' => null,
            'continuous_verification_interval_seconds' => null,
            'device_identity_required' => null,
            'step_up' => null,
            'retention_policies' => null,
            'signal_providers' => null,
            'trust_score_threshold' => null,
        ]);

        self::assertFalse($config->enabled);
        self::assertSame(0.7, $config->defaultMinConfidence);
        self::assertSame(300, $config->continuousVerificationIntervalSeconds);
        self::assertFalse($config->deviceIdentityRequired);
        self::assertSame([], $config->retentionPolicies);
        self::assertSame([], $config->signalProviders);
        self::assertSame(0.6, $config->trustScoreThreshold);
    }

    #[Test]
    public function fromArrayWithMultipleRetentionPolicies(): void
    {
        $config = ZeroTrustConfig::fromArray([
            'retention_policies' => [
                ['source' => 'device_signal', 'retention_seconds' => 3600, 'pseudonymize' => true, 'legal_basis' => 'Consent'],
                ['source' => 'network_signal', 'retention_seconds' => 0, 'pseudonymize' => false, 'legal_basis' => ''],
                ['source' => 'behavior_signal', 'retention_seconds' => 86400],
            ],
        ]);

        self::assertCount(3, $config->retentionPolicies);
        self::assertSame(ClaimSource::DeviceSignal, $config->retentionPolicies[0]->source);
        self::assertSame(3600, $config->retentionPolicies[0]->retentionSeconds);
        self::assertTrue($config->retentionPolicies[0]->pseudonymize);
        self::assertSame(ClaimSource::NetworkSignal, $config->retentionPolicies[1]->source);
        self::assertSame(0, $config->retentionPolicies[1]->retentionSeconds);
        self::assertFalse($config->retentionPolicies[1]->pseudonymize);
        self::assertSame(ClaimSource::BehaviorSignal, $config->retentionPolicies[2]->source);
        self::assertSame(86400, $config->retentionPolicies[2]->retentionSeconds);
    }

    /**
     * @return iterable<string, array{string, mixed, float}>
     */
    public static function confidenceCoercionProvider(): iterable
    {
        yield 'float value' => ['default_min_confidence', 0.5, 0.5];
        yield 'integer zero' => ['default_min_confidence', 0, 0.0];
        yield 'integer one' => ['default_min_confidence', 1, 1.0];
        yield 'string fallback' => ['default_min_confidence', 'invalid', 0.7];
        yield 'null fallback' => ['default_min_confidence', null, 0.7];
        yield 'threshold float' => ['trust_score_threshold', 0.9, 0.9];
        yield 'threshold int' => ['trust_score_threshold', 1, 1.0];
        yield 'threshold string fallback' => ['trust_score_threshold', 'bad', 0.6];
    }

    #[Test]
    #[DataProvider('confidenceCoercionProvider')]
    public function fromArrayHandlesConfidenceCoercion(string $key, mixed $value, float $expected): void
    {
        $config = ZeroTrustConfig::fromArray([$key => $value]);

        $property = match ($key) {
            'default_min_confidence' => $config->defaultMinConfidence,
            'trust_score_threshold' => $config->trustScoreThreshold,
            default => throw new \InvalidArgumentException("Unknown key: $key"),
        };

        self::assertSame($expected, $property);
    }
}
