<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Tracing\Propagation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Tracing\Propagation\BaggageParser;

#[CoversClass(BaggageParser::class)]
final class BaggageParserTest extends TestCase
{
    private BaggageParser $parser;

    protected function setUp(): void
    {
        $this->parser = new BaggageParser();
    }

    #[Test]
    public function parsesSimpleKeyValuePairs(): void
    {
        $result = $this->parser->parse('key1=value1,key2=value2');

        self::assertSame(['key1' => 'value1', 'key2' => 'value2'], $result);
    }

    #[Test]
    public function stripsProperties(): void
    {
        $result = $this->parser->parse('key1=value1;property1=p1,key2=value2;property2');

        self::assertSame(['key1' => 'value1', 'key2' => 'value2'], $result);
    }

    #[Test]
    public function urlDecodesValues(): void
    {
        $result = $this->parser->parse('key1=hello%20world,key2=foo%3Dbar');

        self::assertSame([
            'key1' => 'hello world',
            'key2' => 'foo=bar',
        ], $result);
    }

    #[Test]
    public function parsesEmptyHeader(): void
    {
        self::assertSame([], $this->parser->parse(''));
        self::assertSame([], $this->parser->parse('   '));
    }

    #[Test]
    public function skipsInvalidEntries(): void
    {
        $result = $this->parser->parse('valid=yes,invalid_no_equals,=empty_key,key3=ok');

        self::assertSame(['valid' => 'yes', 'key3' => 'ok'], $result);
    }

    #[Test]
    public function handlesWhitespace(): void
    {
        $result = $this->parser->parse(' key1 = value1 , key2 = value2 ');

        self::assertSame(['key1' => 'value1', 'key2' => 'value2'], $result);
    }

    #[Test]
    public function handlesSingleEntry(): void
    {
        $result = $this->parser->parse('sessionId=abc123');

        self::assertSame(['sessionId' => 'abc123'], $result);
    }

    #[Test]
    public function serializesKeyValuePairs(): void
    {
        $result = $this->parser->serialize(['key1' => 'value1', 'key2' => 'value2']);

        self::assertSame('key1=value1,key2=value2', $result);
    }

    #[Test]
    public function serializesEmptyArray(): void
    {
        self::assertSame('', $this->parser->serialize([]));
    }

    #[Test]
    public function serializesWithUrlEncoding(): void
    {
        $result = $this->parser->serialize([
            'key1' => 'hello world',
            'key2' => 'foo=bar',
        ]);

        self::assertSame('key1=hello%20world,key2=foo%3Dbar', $result);
    }

    #[Test]
    public function roundTripPreservesValues(): void
    {
        $original = ['userId' => '12345', 'theme' => 'dark mode', 'lang' => 'en-US'];

        $serialized = $this->parser->serialize($original);
        $parsed = $this->parser->parse($serialized);

        self::assertSame($original, $parsed);
    }

    #[Test]
    public function parseHandlesEqualsInValue(): void
    {
        $result = $this->parser->parse('token=abc=def=ghi');

        self::assertSame(['token' => 'abc=def=ghi'], $result);
    }
}
