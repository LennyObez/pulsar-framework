<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Claim;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ZeroTrust\Claim\Claim;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;

use function count;

#[CoversClass(ClaimSet::class)]
final class ClaimSetTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable();
    }

    #[Test]
    public function emptySetHasZeroCount(): void
    {
        $set = new ClaimSet();

        self::assertCount(0, $set);
        self::assertSame([], $set->all());
    }

    #[Test]
    public function constructsWithClaims(): void
    {
        $claim = $this->makeClaim('test.one', true, ClaimSource::DeviceSignal, 0.9);
        $set = new ClaimSet([$claim]);

        self::assertCount(1, $set);
        self::assertSame([$claim], $set->all());
    }

    #[Test]
    public function getByNameReturnsMatchingClaims(): void
    {
        $a = $this->makeClaim('device.registered', true, ClaimSource::DeviceSignal, 0.9);
        $b = $this->makeClaim('device.known', true, ClaimSource::DeviceSignal, 0.7);
        $c = $this->makeClaim('device.registered', false, ClaimSource::DeviceSignal, 0.3);

        $set = new ClaimSet([$a, $b, $c]);

        $result = $set->getByName('device.registered');

        self::assertCount(2, $result);
        self::assertSame($a, $result[0]);
        self::assertSame($c, $result[1]);
    }

    #[Test]
    public function getByNameReturnsEmptyForNoMatches(): void
    {
        $set = new ClaimSet([$this->makeClaim('test.a', true, ClaimSource::DeviceSignal, 0.5)]);

        self::assertSame([], $set->getByName('test.b'));
    }

    #[Test]
    public function firstReturnsFirstMatchingClaim(): void
    {
        $a = $this->makeClaim('device.registered', true, ClaimSource::DeviceSignal, 0.9);
        $b = $this->makeClaim('device.known', false, ClaimSource::DeviceSignal, 0.7);
        $c = $this->makeClaim('device.registered', false, ClaimSource::DeviceSignal, 0.3);

        $set = new ClaimSet([$a, $b, $c]);

        self::assertSame($a, $set->first('device.registered'));
    }

    #[Test]
    public function firstReturnsNullWhenNoMatch(): void
    {
        $set = new ClaimSet([$this->makeClaim('test.a', true, ClaimSource::DeviceSignal, 0.5)]);

        self::assertNull($set->first('test.nonexistent'));
    }

    #[Test]
    public function filterBySourceReturnsMatchingClaims(): void
    {
        $device = $this->makeClaim('device.known', true, ClaimSource::DeviceSignal, 0.9);
        $location = $this->makeClaim('location.country', 'US', ClaimSource::LocationSignal, 0.8);
        $network = $this->makeClaim('network.zone', 'internal', ClaimSource::NetworkSignal, 0.95);

        $set = new ClaimSet([$device, $location, $network]);
        $filtered = $set->filterBySource(ClaimSource::LocationSignal);

        self::assertCount(1, $filtered);
        self::assertSame('location.country', $filtered->all()[0]->name);
    }

    #[Test]
    public function filterByMinConfidenceExcludesLowConfidence(): void
    {
        $high = $this->makeClaim('test.high', true, ClaimSource::DeviceSignal, 0.95);
        $medium = $this->makeClaim('test.medium', true, ClaimSource::DeviceSignal, 0.7);
        $low = $this->makeClaim('test.low', true, ClaimSource::DeviceSignal, 0.3);

        $set = new ClaimSet([$high, $medium, $low]);
        $filtered = $set->filterByMinConfidence(0.7);

        self::assertCount(2, $filtered);
        self::assertSame('test.high', $filtered->all()[0]->name);
        self::assertSame('test.medium', $filtered->all()[1]->name);
    }

    #[Test]
    public function filterByMinConfidenceIncludesExactThreshold(): void
    {
        $exact = $this->makeClaim('test.exact', true, ClaimSource::DeviceSignal, 0.8);
        $below = $this->makeClaim('test.below', true, ClaimSource::DeviceSignal, 0.79);

        $set = new ClaimSet([$exact, $below]);
        $filtered = $set->filterByMinConfidence(0.8);

        self::assertCount(1, $filtered);
        self::assertSame('test.exact', $filtered->all()[0]->name);
    }

    #[Test]
    public function hasReturnsTrueWhenClaimExists(): void
    {
        $set = new ClaimSet([$this->makeClaim('device.known', true, ClaimSource::DeviceSignal, 0.9)]);

        self::assertTrue($set->has('device.known'));
    }

    #[Test]
    public function hasReturnsFalseWhenClaimDoesNotExist(): void
    {
        $set = new ClaimSet([$this->makeClaim('device.known', true, ClaimSource::DeviceSignal, 0.9)]);

        self::assertFalse($set->has('device.unknown'));
    }

    #[Test]
    public function mergesCombinesTwoSets(): void
    {
        $a = new ClaimSet([$this->makeClaim('test.a', true, ClaimSource::DeviceSignal, 0.9)]);
        $b = new ClaimSet([$this->makeClaim('test.b', false, ClaimSource::LocationSignal, 0.8)]);

        $merged = $a->merge($b);

        self::assertCount(2, $merged);
        self::assertTrue($merged->has('test.a'));
        self::assertTrue($merged->has('test.b'));
    }

    #[Test]
    public function mergeDoesNotMutateOriginals(): void
    {
        $a = new ClaimSet([$this->makeClaim('test.a', true, ClaimSource::DeviceSignal, 0.9)]);
        $b = new ClaimSet([$this->makeClaim('test.b', false, ClaimSource::LocationSignal, 0.8)]);

        (void) $a->merge($b);

        self::assertCount(1, $a);
        self::assertCount(1, $b);
    }

    #[Test]
    public function isIterableWithForeach(): void
    {
        $claims = [
            $this->makeClaim('test.a', true, ClaimSource::DeviceSignal, 0.9),
            $this->makeClaim('test.b', false, ClaimSource::LocationSignal, 0.8),
        ];

        $set = new ClaimSet($claims);
        $iterated = [];

        foreach ($set as $claim) {
            $iterated[] = $claim;
        }

        self::assertCount(2, $iterated);
        self::assertSame($claims[0], $iterated[0]);
        self::assertSame($claims[1], $iterated[1]);
    }

    #[Test]
    public function countIsConsistentWithAll(): void
    {
        $set = new ClaimSet([
            $this->makeClaim('a', true, ClaimSource::DeviceSignal, 0.5),
            $this->makeClaim('b', true, ClaimSource::DeviceSignal, 0.5),
            $this->makeClaim('c', true, ClaimSource::DeviceSignal, 0.5),
        ]);

        self::assertSame($set->count(), count($set->all()));
    }

    #[Test]
    public function filterChainingWorks(): void
    {
        $set = new ClaimSet([
            $this->makeClaim('device.known', true, ClaimSource::DeviceSignal, 0.95),
            $this->makeClaim('device.registered', true, ClaimSource::DeviceSignal, 0.5),
            $this->makeClaim('location.country', 'US', ClaimSource::LocationSignal, 0.85),
        ]);

        $result = $set->filterBySource(ClaimSource::DeviceSignal)->filterByMinConfidence(0.8);

        self::assertCount(1, $result);
        self::assertSame('device.known', $result->all()[0]->name);
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
