<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Privacy;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\Privacy\SignalRetentionPolicy;
use ValueError;

#[CoversClass(SignalRetentionPolicy::class)]
final class SignalRetentionPolicyTest extends TestCase
{
    #[Test]
    public function constructs_with_required_fields(): void
    {
        $policy = new SignalRetentionPolicy(
            source: ClaimSource::DeviceSignal,
            retentionSeconds: 86400,
        );

        self::assertSame(ClaimSource::DeviceSignal, $policy->source);
        self::assertSame(86400, $policy->retentionSeconds);
        self::assertFalse($policy->pseudonymize);
        self::assertSame('', $policy->legalBasis);
    }

    #[Test]
    public function constructs_with_all_fields(): void
    {
        $policy = new SignalRetentionPolicy(
            source: ClaimSource::NetworkSignal,
            retentionSeconds: 604800,
            pseudonymize: true,
            legalBasis: 'GDPR Article 6(1)(f)',
        );

        self::assertSame(ClaimSource::NetworkSignal, $policy->source);
        self::assertSame(604800, $policy->retentionSeconds);
        self::assertTrue($policy->pseudonymize);
        self::assertSame('GDPR Article 6(1)(f)', $policy->legalBasis);
    }

    #[Test]
    public function rejects_negative_retention(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retention');

        new SignalRetentionPolicy(
            source: ClaimSource::DeviceSignal,
            retentionSeconds: -1,
        );
    }

    #[Test]
    public function allows_zero_retention(): void
    {
        $policy = new SignalRetentionPolicy(
            source: ClaimSource::DeviceSignal,
            retentionSeconds: 0,
        );

        self::assertSame(0, $policy->retentionSeconds);
    }

    #[Test]
    public function from_array_parses_valid_data(): void
    {
        $policy = SignalRetentionPolicy::fromArray([
            'source' => 'device_signal',
            'retention_seconds' => 3600,
            'pseudonymize' => true,
            'legal_basis' => 'Legitimate interest',
        ]);

        self::assertSame(ClaimSource::DeviceSignal, $policy->source);
        self::assertSame(3600, $policy->retentionSeconds);
        self::assertTrue($policy->pseudonymize);
        self::assertSame('Legitimate interest', $policy->legalBasis);
    }

    #[Test]
    public function from_array_uses_defaults_for_optional_fields(): void
    {
        $policy = SignalRetentionPolicy::fromArray([
            'source' => 'network_signal',
        ]);

        self::assertSame(ClaimSource::NetworkSignal, $policy->source);
        self::assertSame(0, $policy->retentionSeconds);
        self::assertFalse($policy->pseudonymize);
        self::assertSame('', $policy->legalBasis);
    }

    #[Test]
    public function from_array_rejects_invalid_source(): void
    {
        $this->expectException(ValueError::class);

        (void) SignalRetentionPolicy::fromArray([
            'source' => 'invalid_source',
        ]);
    }
}
