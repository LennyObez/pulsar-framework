<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Claim;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;

#[CoversClass(ClaimSource::class)]
final class ClaimSourceTest extends TestCase
{
    #[Test]
    public function hasFiveCases(): void
    {
        self::assertCount(5, ClaimSource::cases());
    }

    #[Test]
    #[DataProvider('sourceProvider')]
    public function backedValues(ClaimSource $source, string $expected): void
    {
        self::assertSame($expected, $source->value);
    }

    /**
     * @return iterable<string, array{ClaimSource, string}>
     */
    public static function sourceProvider(): iterable
    {
        yield 'DeviceSignal' => [ClaimSource::DeviceSignal, 'device_signal'];
        yield 'LocationSignal' => [ClaimSource::LocationSignal, 'location_signal'];
        yield 'TimeSignal' => [ClaimSource::TimeSignal, 'time_signal'];
        yield 'BehaviorSignal' => [ClaimSource::BehaviorSignal, 'behavior_signal'];
        yield 'NetworkSignal' => [ClaimSource::NetworkSignal, 'network_signal'];
    }

    #[Test]
    public function fromBackedValueRoundTrips(): void
    {
        foreach (ClaimSource::cases() as $source) {
            self::assertSame($source, ClaimSource::from($source->value));
        }
    }

    #[Test]
    public function tryFromReturnsNullForInvalid(): void
    {
        self::assertNull(ClaimSource::tryFrom('biometric'));
    }
}
