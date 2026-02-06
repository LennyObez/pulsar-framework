<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Claim;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ZeroTrust\Claim\Claim;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;

#[CoversClass(Claim::class)]
final class ClaimTest extends TestCase
{
    #[Test]
    public function constructsWithValidParameters(): void
    {
        $now = new DateTimeImmutable();
        $claim = new Claim(
            name: 'device.registered',
            value: true,
            source: ClaimSource::DeviceSignal,
            confidence: 0.95,
            timestamp: $now,
        );

        self::assertSame('device.registered', $claim->name);
        self::assertTrue($claim->value);
        self::assertSame(ClaimSource::DeviceSignal, $claim->source);
        self::assertSame(0.95, $claim->confidence);
        self::assertSame($now, $claim->timestamp);
    }

    #[Test]
    public function acceptsZeroConfidence(): void
    {
        $claim = new Claim(
            name: 'test.claim',
            value: null,
            source: ClaimSource::NetworkSignal,
            confidence: 0.0,
            timestamp: new DateTimeImmutable(),
        );

        self::assertSame(0.0, $claim->confidence);
    }

    #[Test]
    public function acceptsFullConfidence(): void
    {
        $claim = new Claim(
            name: 'test.claim',
            value: 'verified',
            source: ClaimSource::LocationSignal,
            confidence: 1.0,
            timestamp: new DateTimeImmutable(),
        );

        self::assertSame(1.0, $claim->confidence);
    }

    #[Test]
    public function rejectsNegativeConfidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Claim confidence must be between 0.0 and 1.0');

        new Claim(
            name: 'test.claim',
            value: true,
            source: ClaimSource::DeviceSignal,
            confidence: -0.1,
            timestamp: new DateTimeImmutable(),
        );
    }

    #[Test]
    public function rejectsConfidenceAboveOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Claim confidence must be between 0.0 and 1.0');

        new Claim(
            name: 'test.claim',
            value: true,
            source: ClaimSource::DeviceSignal,
            confidence: 1.01,
            timestamp: new DateTimeImmutable(),
        );
    }

    #[Test]
    public function rejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Claim name must not be empty');

        new Claim(
            name: '',
            value: true,
            source: ClaimSource::DeviceSignal,
            confidence: 0.5,
            timestamp: new DateTimeImmutable(),
        );
    }

    #[Test]
    public function acceptsStringValue(): void
    {
        $claim = new Claim(
            name: 'location.country',
            value: 'US',
            source: ClaimSource::LocationSignal,
            confidence: 0.85,
            timestamp: new DateTimeImmutable(),
        );

        self::assertSame('US', $claim->value);
    }

    #[Test]
    public function acceptsNullValue(): void
    {
        $claim = new Claim(
            name: 'test.nullable',
            value: null,
            source: ClaimSource::TimeSignal,
            confidence: 0.5,
            timestamp: new DateTimeImmutable(),
        );

        self::assertNull($claim->value);
    }

    #[Test]
    public function acceptsArrayValue(): void
    {
        $zones = ['internal', 'vpn'];
        $claim = new Claim(
            name: 'network.zones',
            value: $zones,
            source: ClaimSource::NetworkSignal,
            confidence: 0.9,
            timestamp: new DateTimeImmutable(),
        );

        self::assertSame($zones, $claim->value);
    }

    #[Test]
    public function allSourcesAreUsable(): void
    {
        $now = new DateTimeImmutable();

        foreach (ClaimSource::cases() as $source) {
            $claim = new Claim(
                name: 'test.' . $source->value,
                value: true,
                source: $source,
                confidence: 0.5,
                timestamp: $now,
            );

            self::assertSame($source, $claim->source);
        }
    }
}
