<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Idempotency;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Idempotency\IdempotencyClaim;
use Pulsar\Idempotency\IdempotencyClaimStatus;

#[CoversClass(IdempotencyClaim::class)]
final class IdempotencyClaimTest extends TestCase
{
    #[Test]
    public function replayCreatesClaimWithReplayStatusAndPayload(): void
    {
        $claim = IdempotencyClaim::replay('{"result":"ok"}');

        self::assertSame(IdempotencyClaimStatus::Replay, $claim->status);
        self::assertSame('{"result":"ok"}', $claim->resultPayload);
    }

    #[Test]
    public function claimedCreatesClaimWithClaimedStatusAndNullPayload(): void
    {
        $claim = IdempotencyClaim::claimed();

        self::assertSame(IdempotencyClaimStatus::Claimed, $claim->status);
        self::assertNull($claim->resultPayload);
    }

    #[Test]
    public function mismatchCreatesClaimWithMismatchStatusAndNullPayload(): void
    {
        $claim = IdempotencyClaim::mismatch();

        self::assertSame(IdempotencyClaimStatus::Mismatch, $claim->status);
        self::assertNull($claim->resultPayload);
    }

    #[Test]
    public function replayPreservesEmptyStringPayload(): void
    {
        $claim = IdempotencyClaim::replay('');

        self::assertSame(IdempotencyClaimStatus::Replay, $claim->status);
        self::assertSame('', $claim->resultPayload);
    }
}
