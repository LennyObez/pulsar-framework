<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Tests\Unit\Access;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\DataAct\Access\ThirdPartyAccessResult;

#[CoversClass(ThirdPartyAccessResult::class)]
final class ThirdPartyAccessResultTest extends TestCase
{
    #[Test]
    public function grantedResultContainsAllIdentifiers(): void
    {
        $result = ThirdPartyAccessResult::granted('partner', 'subject', 'research');

        self::assertTrue($result->granted);
        self::assertSame('partner', $result->requestingParty);
        self::assertSame('subject', $result->dataSubjectId);
        self::assertSame('research', $result->purpose);
        self::assertStringContainsString('Art. 6', $result->reason);
    }

    #[Test]
    public function deniedResultContainsReason(): void
    {
        $result = ThirdPartyAccessResult::denied('Policy violation');

        self::assertFalse($result->granted);
        self::assertSame('Policy violation', $result->reason);
        self::assertSame('', $result->requestingParty);
        self::assertSame('', $result->dataSubjectId);
        self::assertSame('', $result->purpose);
    }
}
