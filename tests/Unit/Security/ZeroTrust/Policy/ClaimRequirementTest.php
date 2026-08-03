<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Policy;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\Policy\ClaimRequirement;

final class ClaimRequirementTest extends TestCase
{
    #[Test]
    public function constructs_with_name_only(): void
    {
        $req = new ClaimRequirement(claimName: 'device.registered');

        self::assertSame('device.registered', $req->claimName);
        self::assertSame(0.0, $req->minConfidence);
        self::assertSame([], $req->allowedSources);
    }

    #[Test]
    public function constructs_with_all_fields(): void
    {
        $req = new ClaimRequirement(
            claimName: 'network.zone',
            minConfidence: 0.8,
            allowedSources: [ClaimSource::NetworkSignal],
        );

        self::assertSame('network.zone', $req->claimName);
        self::assertSame(0.8, $req->minConfidence);
        self::assertSame([ClaimSource::NetworkSignal], $req->allowedSources);
    }

    #[Test]
    public function rejects_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('must not be empty');

        new ClaimRequirement(claimName: '');
    }

    #[Test]
    #[DataProvider('invalidConfidences')]
    public function rejects_out_of_range_confidence(float $confidence): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('minConfidence');

        new ClaimRequirement(claimName: 'test', minConfidence: $confidence);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidConfidences(): iterable
    {
        yield 'negative' => [-0.1];
        yield 'greater than 1' => [1.1];
        yield 'far out of range' => [5.0];
    }

    #[Test]
    public function accepts_boundary_confidences(): void
    {
        $zero = new ClaimRequirement(claimName: 'test', minConfidence: 0.0);
        $one = new ClaimRequirement(claimName: 'test', minConfidence: 1.0);

        self::assertSame(0.0, $zero->minConfidence);
        self::assertSame(1.0, $one->minConfidence);
    }

    #[Test]
    public function accepts_multiple_allowed_sources(): void
    {
        $req = new ClaimRequirement(
            claimName: 'identity',
            allowedSources: [ClaimSource::DeviceSignal, ClaimSource::BehaviorSignal],
        );

        self::assertCount(2, $req->allowedSources);
    }
}
