<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
}
