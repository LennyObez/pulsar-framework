<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Http3;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Http3\AltSvcEntry;
use Pulsar\Http\Http3\AltSvcHeader;

#[CoversClass(AltSvcHeader::class)]
#[CoversClass(AltSvcEntry::class)]
final class AltSvcHeaderTest extends TestCase
{
    #[Test]
    public function h3GeneratesCorrectHeader(): void
    {
        $altSvc = new AltSvcHeader();
        $altSvc->h3(port: 443, maxAge: 86400);

        self::assertSame('h3=":443"; ma=86400', $altSvc->toHeaderValue());
    }

    #[Test]
    public function h3WithPersist(): void
    {
        $altSvc = new AltSvcHeader();
        $altSvc->h3(port: 443, maxAge: 3600, persist: true);

        self::assertSame('h3=":443"; ma=3600; persist=1', $altSvc->toHeaderValue());
    }

    #[Test]
    public function h2GeneratesCorrectHeader(): void
    {
        $altSvc = new AltSvcHeader();
        $altSvc->h2(port: 443, maxAge: 3600);

        self::assertSame('h2=":443"; ma=3600', $altSvc->toHeaderValue());
    }

    #[Test]
    public function multipleEntriesCombine(): void
    {
        $altSvc = new AltSvcHeader();
        $altSvc->h3(443, 86400);
        $altSvc->h2(443, 3600);

        $header = $altSvc->toHeaderValue();

        self::assertStringContainsString('h3=":443"; ma=86400', $header);
        self::assertStringContainsString('h2=":443"; ma=3600', $header);
        self::assertStringContainsString(', ', $header);
    }

    #[Test]
    public function emptyReturnsClear(): void
    {
        $altSvc = new AltSvcHeader();

        self::assertSame('clear', $altSvc->toHeaderValue());
        self::assertTrue($altSvc->isEmpty());
    }

    #[Test]
    public function clearRemovesAllEntries(): void
    {
        $altSvc = new AltSvcHeader();
        $altSvc->h3(443);
        $altSvc->clear();

        self::assertSame('clear', $altSvc->toHeaderValue());
        self::assertTrue($altSvc->isEmpty());
    }

    #[Test]
    public function addEntrySupportsCustomProtocol(): void
    {
        $altSvc = new AltSvcHeader();
        $altSvc->addEntry('h3-29', 8443, 7200);

        self::assertSame('h3-29=":8443"; ma=7200', $altSvc->toHeaderValue());
    }

    #[Test]
    public function entriesReturnsAllEntries(): void
    {
        $altSvc = new AltSvcHeader();
        $altSvc->h3(443);
        $altSvc->h2(443);

        $entries = $altSvc->entries();

        self::assertCount(2, $entries);
        self::assertContainsOnlyInstancesOf(AltSvcEntry::class, $entries);
    }

    #[Test]
    public function altSvcEntryToHeaderValue(): void
    {
        $entry = new AltSvcEntry(protocol: 'h3', port: 8443, maxAge: 7200, persist: false);

        self::assertSame('h3=":8443"; ma=7200', $entry->toHeaderValue());
    }

    #[Test]
    public function altSvcEntryWithPersist(): void
    {
        $entry = new AltSvcEntry(protocol: 'h3', port: 443, maxAge: 86400, persist: true);

        self::assertSame('h3=":443"; ma=86400; persist=1', $entry->toHeaderValue());
    }

    #[Test]
    public function nonStandardPort(): void
    {
        $altSvc = new AltSvcHeader();
        $altSvc->h3(port: 8443);

        self::assertSame('h3=":8443"; ma=86400', $altSvc->toHeaderValue());
    }

    #[Test]
    public function h3MethodIsFluent(): void
    {
        $altSvc = new AltSvcHeader();
        $returned = $altSvc->h3(443);

        self::assertSame($altSvc, $returned);
    }
}
