<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Idempotency;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Idempotency\IdempotencyClaim;
use Pulsar\Idempotency\IdempotencyClaimStatus;

#[CoversClass(IdempotencyClaim::class)]
final class IdempotencyClaimTest extends TestCase
{
    #[Test]
    public function replayHasStatusAndPayload(): void
    {
        $claim = IdempotencyClaim::replay('{"data":"cached"}');

        self::assertSame(IdempotencyClaimStatus::Replay, $claim->status);
        self::assertSame('{"data":"cached"}', $claim->resultPayload);
    }

    #[Test]
    public function claimedHasStatusAndNullPayload(): void
    {
        $claim = IdempotencyClaim::claimed();

        self::assertSame(IdempotencyClaimStatus::Claimed, $claim->status);
        self::assertNull($claim->resultPayload);
    }

    #[Test]
    public function mismatchHasStatusAndNullPayload(): void
    {
        $claim = IdempotencyClaim::mismatch();

        self::assertSame(IdempotencyClaimStatus::Mismatch, $claim->status);
        self::assertNull($claim->resultPayload);
    }
}
