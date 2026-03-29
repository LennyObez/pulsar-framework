<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Exception\UnsafeHeaderException;
use Pulsar\Http\HeaderBag;

#[CoversClass(HeaderBag::class)]
final class HeaderBagTest extends TestCase
{
    #[Test]
    public function emptyBagHasNoHeaders(): void
    {
        $bag = new HeaderBag();

        self::assertTrue($bag->isEmpty());
        self::assertSame(0, $bag->count());
    }

    #[Test]
    public function constructorAcceptsHeaders(): void
    {
        $bag = new HeaderBag([
            'Content-Type' => 'text/html',
            'X-Custom' => ['value1', 'value2'],
        ]);

        self::assertFalse($bag->isEmpty());
        self::assertSame(2, $bag->count());
    }

    #[Test]
    public function getReturnsAllValuesForHeader(): void
    {
        $bag = new HeaderBag([
            'Accept' => ['text/html', 'application/json'],
        ]);

        self::assertSame(['text/html', 'application/json'], $bag->get('Accept'));
    }

    #[Test]
    public function getIsCaseInsensitive(): void
    {
        $bag = new HeaderBag([
            'Content-Type' => 'text/html',
        ]);

        self::assertSame(['text/html'], $bag->get('content-type'));
        self::assertSame(['text/html'], $bag->get('CONTENT-TYPE'));
    }

    #[Test]
    public function getReturnsEmptyArrayForMissingHeader(): void
    {
        $bag = new HeaderBag();

        self::assertSame([], $bag->get('Missing'));
    }

    #[Test]
    public function firstReturnsFirstValue(): void
    {
        $bag = new HeaderBag([
            'Accept' => ['text/html', 'application/json'],
        ]);

        self::assertSame('text/html', $bag->first('Accept'));
    }

    #[Test]
    public function firstReturnsDefaultForMissingHeader(): void
    {
        $bag = new HeaderBag();

        self::assertNull($bag->first('Missing'));
        self::assertSame('default', $bag->first('Missing', 'default'));
    }

    #[Test]
    public function hasReturnsTrueForExistingHeader(): void
    {
        $bag = new HeaderBag(['X-Test' => 'value']);

        self::assertTrue($bag->has('X-Test'));
        self::assertTrue($bag->has('x-test'));
    }

    #[Test]
    public function hasReturnsFalseForMissingHeader(): void
    {
        $bag = new HeaderBag();

        self::assertFalse($bag->has('Missing'));
    }

    #[Test]
    public function withReturnsNewBagWithHeader(): void
    {
        $original = new HeaderBag(['X-Original' => 'value']);
        $new = $original->with('X-New', 'new-value');

        self::assertNotSame($original, $new);
        self::assertFalse($original->has('X-New'));
        self::assertTrue($new->has('X-New'));
        self::assertTrue($new->has('X-Original'));
    }

    #[Test]
    public function withReplacesExistingHeader(): void
    {
        $bag = new HeaderBag(['X-Test' => 'old']);
        $new = $bag->with('X-Test', 'new');

        self::assertSame(['new'], $new->get('X-Test'));
    }

    #[Test]
    public function withAddedAppendsValue(): void
    {
        $bag = new HeaderBag(['Accept' => 'text/html']);
        $new = $bag->withAdded('Accept', 'application/json');

        self::assertSame(['text/html', 'application/json'], $new->get('Accept'));
    }

    #[Test]
    public function withAddedCreatesNewHeaderIfMissing(): void
    {
        $bag = new HeaderBag();
        $new = $bag->withAdded('X-New', 'value');

        self::assertSame(['value'], $new->get('X-New'));
    }

    #[Test]
    public function withoutRemovesHeader(): void
    {
        $bag = new HeaderBag(['X-Remove' => 'value', 'X-Keep' => 'keep']);
        $new = $bag->without('X-Remove');

        self::assertFalse($new->has('X-Remove'));
        self::assertTrue($new->has('X-Keep'));
    }

    #[Test]
    public function withoutReturnsSameInstanceIfHeaderMissing(): void
    {
        $bag = new HeaderBag(['X-Test' => 'value']);
        $new = $bag->without('Missing');

        self::assertSame($bag, $new);
    }

    #[Test]
    public function toArrayPreservesOriginalCase(): void
    {
        $bag = new HeaderBag([
            'Content-Type' => 'text/html',
            'X-Custom-Header' => 'value',
        ]);

        $array = $bag->toArray();

        self::assertArrayHasKey('Content-Type', $array);
        self::assertArrayHasKey('X-Custom-Header', $array);
    }

    #[Test]
    public function toLinesFormatsHeadersForHttp(): void
    {
        $bag = new HeaderBag([
            'Content-Type' => 'text/html',
            'Accept' => ['text/html', 'application/json'],
        ]);

        $lines = $bag->toLines();

        self::assertContains('Content-Type: text/html', $lines);
        self::assertContains('Accept: text/html', $lines);
        self::assertContains('Accept: application/json', $lines);
    }

    #[Test]
    public function canBeIterated(): void
    {
        $bag = new HeaderBag(['X-Test' => 'value']);
        $iterated = [];

        foreach ($bag as $name => $values) {
            $iterated[$name] = $values;
        }

        self::assertArrayHasKey('X-Test', $iterated);
    }

    #[Test]
    public function fromServerExtractsHttpHeaders(): void
    {
        $server = [
            'HTTP_HOST' => 'localhost',
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_X_CUSTOM' => 'value',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '123',
            'REQUEST_METHOD' => 'GET', // Should be ignored
        ];

        $bag = HeaderBag::fromServer($server);

        self::assertTrue($bag->has('Host'));
        self::assertTrue($bag->has('Accept'));
        self::assertTrue($bag->has('X-Custom'));
        self::assertTrue($bag->has('Content-Type'));
        self::assertTrue($bag->has('Content-Length'));
        self::assertFalse($bag->has('Request-Method'));
    }

    #[Test]
    public function constructorRejectsCrlfInValue(): void
    {
        $this->expectException(UnsafeHeaderException::class);

        new HeaderBag(['X-Forwarded-For' => "10.0.0.1\r\nX-Injected: bad"]);
    }

    #[Test]
    public function constructorRejectsLfOnlyInValue(): void
    {
        $this->expectException(UnsafeHeaderException::class);

        new HeaderBag(['X-Trace' => "trace-id\nset-cookie: stolen=1"]);
    }

    #[Test]
    public function constructorRejectsNulInValue(): void
    {
        $this->expectException(UnsafeHeaderException::class);

        new HeaderBag(['X-Custom' => "abc\x00def"]);
    }

    #[Test]
    public function constructorRejectsControlCharactersInValueList(): void
    {
        $this->expectException(UnsafeHeaderException::class);

        new HeaderBag(['Accept' => ['text/plain', "text/html\r\n"]]);
    }

    #[Test]
    public function constructorRejectsEmptyName(): void
    {
        $this->expectException(UnsafeHeaderException::class);

        new HeaderBag(['' => 'value']);
    }

    #[Test]
    public function constructorRejectsNameWithSpace(): void
    {
        $this->expectException(UnsafeHeaderException::class);

        new HeaderBag(['Bad Name' => 'value']);
    }

    #[Test]
    public function constructorRejectsNameWithCrlf(): void
    {
        $this->expectException(UnsafeHeaderException::class);

        new HeaderBag(["X-Custom\r\nInjected" => 'value']);
    }

    #[Test]
    public function constructorRejectsNameWithControlByte(): void
    {
        $this->expectException(UnsafeHeaderException::class);

        new HeaderBag(["X-Bad\x00" => 'value']);
    }

    #[Test]
    public function withRejectsCrlfInValue(): void
    {
        $bag = new HeaderBag();

        $this->expectException(UnsafeHeaderException::class);

        (void) $bag->with('Location', "/safe\r\nSet-Cookie: forged=1");
    }

    #[Test]
    public function withAddedRejectsCrlfInValue(): void
    {
        $bag = new HeaderBag();

        $this->expectException(UnsafeHeaderException::class);

        (void) $bag->withAdded('X-Trace', "value\nSet-Cookie: x=1");
    }

    #[Test]
    public function constructorAcceptsTabInValue(): void
    {
        // Tab (0x09) is permitted as a horizontal whitespace character
        // inside header values per RFC 7230 §3.2.6 (obs-fold predecessor).
        $bag = new HeaderBag(['X-Allow-Tab' => "left\tright"]);

        self::assertSame("left\tright", $bag->first('X-Allow-Tab'));
    }
}
