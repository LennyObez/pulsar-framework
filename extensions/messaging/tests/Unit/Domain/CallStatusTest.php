<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Messaging\Domain\CallStatus;

#[CoversClass(CallStatus::class)]
final class CallStatusTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $cases = CallStatus::cases();

        self::assertCount(6, $cases);
        self::assertSame('pending', CallStatus::Pending->value);
        self::assertSame('ringing', CallStatus::Ringing->value);
        self::assertSame('active', CallStatus::Active->value);
        self::assertSame('ended', CallStatus::Ended->value);
        self::assertSame('rejected', CallStatus::Rejected->value);
        self::assertSame('missed', CallStatus::Missed->value);
    }
}
