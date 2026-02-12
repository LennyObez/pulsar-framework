<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Evidence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Evidence\ChainLink;

final class ChainLinkTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $link = new ChainLink(
            eventId: 'evt-1',
            previousHash: 'prev_hash',
            currentHash: 'curr_hash',
            linkMac: 'mac123',
        );

        self::assertSame('evt-1', $link->eventId);
        self::assertSame('prev_hash', $link->previousHash);
        self::assertSame('curr_hash', $link->currentHash);
        self::assertSame('mac123', $link->linkMac);
    }

    #[Test]
    public function linkMacCanBeNull(): void
    {
        $link = new ChainLink(
            eventId: 'evt-2',
            previousHash: 'prev',
            currentHash: 'curr',
            linkMac: null,
        );

        self::assertNull($link->linkMac);
    }
}
