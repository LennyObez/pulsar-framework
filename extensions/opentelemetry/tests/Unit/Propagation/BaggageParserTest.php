<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Propagation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Propagation\BaggageParser;

#[CoversClass(BaggageParser::class)]
final class BaggageParserTest extends TestCase
{
    private BaggageParser $parser;

    protected function setUp(): void
    {
        $this->parser = new BaggageParser();
    }

    #[Test]
    public function parseSingleEntry(): void
    {
        $result = $this->parser->parse('key1=value1');

        self::assertSame(['key1' => 'value1'], $result);
    }

    #[Test]
    public function parseMultipleEntries(): void
    {
        $result = $this->parser->parse('key1=value1,key2=value2');

        self::assertSame(['key1' => 'value1', 'key2' => 'value2'], $result);
    }

    #[Test]
    public function parseStripsProperties(): void
    {
        $result = $this->parser->parse('key1=value1;property1=p,key2=value2;prop2');

        self::assertSame(['key1' => 'value1', 'key2' => 'value2'], $result);
    }

    #[Test]
    public function parseUrlDecodesValues(): void
    {
        $result = $this->parser->parse('key1=hello%20world');

        self::assertSame(['key1' => 'hello world'], $result);
    }

    #[Test]
    public function parseEmptyStringReturnsEmpty(): void
    {
        self::assertSame([], $this->parser->parse(''));
    }

    #[Test]
    public function parseWhitespaceOnlyReturnsEmpty(): void
    {
        self::assertSame([], $this->parser->parse('   '));
    }

    #[Test]
    public function parseSkipsEntriesWithoutEquals(): void
    {
        $result = $this->parser->parse('valid=yes,no-equals,also=good');

        self::assertSame(['valid' => 'yes', 'also' => 'good'], $result);
    }

    #[Test]
    public function parseSkipsEmptyKeys(): void
    {
        $result = $this->parser->parse('=nokey,valid=yes');

        self::assertSame(['valid' => 'yes'], $result);
    }

    #[Test]
    public function parseSkipsEmptyEntries(): void
    {
        $result = $this->parser->parse('key1=val1,,key2=val2,');

        self::assertSame(['key1' => 'val1', 'key2' => 'val2'], $result);
    }

    #[Test]
    public function parseRejectsOversizedHeader(): void
    {
        $oversized = str_repeat('x', 8193);

        self::assertSame([], $this->parser->parse($oversized));
    }

    #[Test]
    public function parseEnforcesMaxEntries(): void
    {
        $entries = [];
        for ($i = 0; $i < 200; $i++) {
            $entries[] = "key{$i}=val{$i}";
        }
        $header = implode(',', $entries);

        $result = $this->parser->parse($header);

        self::assertCount(180, $result);
    }

    #[Test]
    public function parseSkipsOversizedKeys(): void
    {
        $longKey = str_repeat('k', 129);
        $result = $this->parser->parse("{$longKey}=value,short=ok");

        self::assertSame(['short' => 'ok'], $result);
    }

    #[Test]
    public function parseSkipsOversizedValues(): void
    {
        $longValue = str_repeat('v', 257);
        $encoded = rawurlencode($longValue);
        $result = $this->parser->parse("big={$encoded},small=ok");

        self::assertSame(['small' => 'ok'], $result);
    }

    #[Test]
    public function serializeEmptyBaggageReturnsEmpty(): void
    {
        self::assertSame('', $this->parser->serialize([]));
    }

    #[Test]
    public function serializeSingleEntry(): void
    {
        $result = $this->parser->serialize(['key1' => 'value1']);

        self::assertSame('key1=value1', $result);
    }

    #[Test]
    public function serializeMultipleEntries(): void
    {
        $result = $this->parser->serialize(['key1' => 'val1', 'key2' => 'val2']);

        self::assertSame('key1=val1,key2=val2', $result);
    }

    #[Test]
    public function serializeUrlEncodesValues(): void
    {
        $result = $this->parser->serialize(['key' => 'hello world']);

        self::assertSame('key=hello%20world', $result);
    }

    #[Test]
    public function roundTripPreservesData(): void
    {
        $original = ['userId' => '12345', 'tenant' => 'acme corp'];
        $serialized = $this->parser->serialize($original);
        $parsed = $this->parser->parse($serialized);

        self::assertSame($original, $parsed);
    }
}
