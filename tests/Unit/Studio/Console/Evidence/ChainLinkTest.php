<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Evidence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Console\Evidence\ChainLink;
use ReflectionClass;
use ReflectionNamedType;

#[CoversClass(ChainLink::class)]
final class ChainLinkTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $link = new ChainLink(
            eventId: 'event-123',
            previousHash: 'prev-hash-abc',
            currentHash: 'current-hash-xyz',
            linkMac: 'mac-value-456',
        );

        self::assertSame('event-123', $link->eventId);
        self::assertSame('prev-hash-abc', $link->previousHash);
        self::assertSame('current-hash-xyz', $link->currentHash);
        self::assertSame('mac-value-456', $link->linkMac);
    }

    #[Test]
    public function constructorAcceptsNullLinkMac(): void
    {
        $link = new ChainLink(
            eventId: 'event-456',
            previousHash: 'prev-hash',
            currentHash: 'current-hash',
            linkMac: null,
        );

        self::assertSame('event-456', $link->eventId);
        self::assertSame('prev-hash', $link->previousHash);
        self::assertSame('current-hash', $link->currentHash);
        self::assertNull($link->linkMac);
    }

    #[Test]
    public function propertiesAreReadonly(): void
    {
        $link = new ChainLink(
            eventId: 'evt-001',
            previousHash: 'hash-a',
            currentHash: 'hash-b',
            linkMac: 'mac-c',
        );

        $reflection = new ReflectionClass(ChainLink::class);

        self::assertTrue($reflection->isReadOnly());
        self::assertTrue($reflection->getProperty('eventId')->isReadOnly());
        self::assertTrue($reflection->getProperty('previousHash')->isReadOnly());
        self::assertTrue($reflection->getProperty('currentHash')->isReadOnly());
        self::assertTrue($reflection->getProperty('linkMac')->isReadOnly());
    }

    #[Test]
    public function propertiesHaveCorrectTypes(): void
    {
        $reflection = new ReflectionClass(ChainLink::class);

        $eventIdType = $reflection->getProperty('eventId')->getType();
        $previousHashType = $reflection->getProperty('previousHash')->getType();
        $currentHashType = $reflection->getProperty('currentHash')->getType();
        $linkMacType = $reflection->getProperty('linkMac')->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $eventIdType);
        self::assertSame('string', $eventIdType->getName());

        self::assertInstanceOf(ReflectionNamedType::class, $previousHashType);
        self::assertSame('string', $previousHashType->getName());

        self::assertInstanceOf(ReflectionNamedType::class, $currentHashType);
        self::assertSame('string', $currentHashType->getName());

        self::assertInstanceOf(ReflectionNamedType::class, $linkMacType);
        self::assertSame('string', $linkMacType->getName());
        self::assertTrue($linkMacType->allowsNull());
    }

    #[Test]
    public function canStoreEmptyStrings(): void
    {
        $link = new ChainLink(
            eventId: '',
            previousHash: '',
            currentHash: '',
            linkMac: '',
        );

        self::assertSame('', $link->eventId);
        self::assertSame('', $link->previousHash);
        self::assertSame('', $link->currentHash);
        self::assertSame('', $link->linkMac);
    }

    #[Test]
    public function canStoreLongHashValues(): void
    {
        $longPrevHash = str_repeat('a', 64);
        $longCurrentHash = str_repeat('b', 64);
        $longMac = str_repeat('c', 64);

        $link = new ChainLink(
            eventId: 'long-hash-event',
            previousHash: $longPrevHash,
            currentHash: $longCurrentHash,
            linkMac: $longMac,
        );

        self::assertSame($longPrevHash, $link->previousHash);
        self::assertSame($longCurrentHash, $link->currentHash);
        self::assertSame($longMac, $link->linkMac);
    }
}
