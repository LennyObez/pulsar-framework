<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\WebSocket;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\WebSocket\Internal\HandshakeResult;

#[CoversClass(HandshakeResult::class)]
final class HandshakeResultTest extends TestCase
{
    #[Test]
    public function acceptedResultHasCorrectState(): void
    {
        $result = HandshakeResult::accepted('dGhlIHNhbXBsZSBub25jZQ==');

        self::assertTrue($result->accepted);
        self::assertSame('dGhlIHNhbXBsZSBub25jZQ==', $result->acceptKey);
        self::assertSame('', $result->rejectionReason);
    }

    #[Test]
    public function rejectedResultHasCorrectState(): void
    {
        $result = HandshakeResult::rejected('Missing Sec-WebSocket-Key');

        self::assertFalse($result->accepted);
        self::assertSame('', $result->acceptKey);
        self::assertSame('Missing Sec-WebSocket-Key', $result->rejectionReason);
    }
}
