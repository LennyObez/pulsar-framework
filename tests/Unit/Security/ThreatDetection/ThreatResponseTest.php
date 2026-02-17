<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ThreatDetection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ThreatDetection\ThreatResponse;

#[CoversClass(ThreatResponse::class)]
final class ThreatResponseTest extends TestCase
{
    #[Test]
    public function hasFiveCases(): void
    {
        self::assertCount(5, ThreatResponse::cases());
    }

    #[Test]
    #[DataProvider('responseProvider')]
    public function backedValues(ThreatResponse $response, string $expected): void
    {
        self::assertSame($expected, $response->value);
    }

    /**
     * @return iterable<string, array{ThreatResponse, string}>
     */
    public static function responseProvider(): iterable
    {
        yield 'Block' => [ThreatResponse::Block, 'block'];
        yield 'RateLimit' => [ThreatResponse::RateLimit, 'rate_limit'];
        yield 'Challenge' => [ThreatResponse::Challenge, 'challenge'];
        yield 'Alert' => [ThreatResponse::Alert, 'alert'];
        yield 'Log' => [ThreatResponse::Log, 'log'];
    }

    #[Test]
    public function fromBackedValueRoundTrips(): void
    {
        foreach (ThreatResponse::cases() as $response) {
            self::assertSame($response, ThreatResponse::from($response->value));
        }
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(ThreatResponse::tryFrom('quarantine'));
    }
}
