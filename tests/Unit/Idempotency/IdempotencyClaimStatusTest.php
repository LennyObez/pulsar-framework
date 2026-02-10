<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Idempotency;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Idempotency\IdempotencyClaimStatus;

#[CoversClass(IdempotencyClaimStatus::class)]
final class IdempotencyClaimStatusTest extends TestCase
{
    #[Test]
    public function hasExpectedCases(): void
    {
        $cases = IdempotencyClaimStatus::cases();

        self::assertCount(3, $cases);
        self::assertContains(IdempotencyClaimStatus::Replay, $cases);
        self::assertContains(IdempotencyClaimStatus::Claimed, $cases);
        self::assertContains(IdempotencyClaimStatus::Mismatch, $cases);
    }
}
