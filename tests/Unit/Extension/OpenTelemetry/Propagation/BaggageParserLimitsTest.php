<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OpenTelemetry\Propagation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Propagation\BaggageParser;

#[CoversClass(BaggageParser::class)]
final class BaggageParserLimitsTest extends TestCase
{
    private BaggageParser $parser;

    protected function setUp(): void
    {
        $this->parser = new BaggageParser();
    }

    #[Test]
    public function rejectsHeaderExceedingMaxSize(): void
    {
        // MAX_HEADER_SIZE = 8192
        $header = str_repeat('a', 8193);

        self::assertSame([], $this->parser->parse($header));
    }

    #[Test]
    public function acceptsHeaderUnderMaxSize(): void
    {
        // Build a valid header under 8192 bytes with value under 256 chars
        $key = 'k';
        $value = str_repeat('v', 200);
        $header = "$key=$value";

        $result = $this->parser->parse($header);
        self::assertArrayHasKey('k', $result);
        self::assertSame($value, $result['k']);
    }

    #[Test]
    public function skipsKeyExceedingMaxKeyLength(): void
    {
        // MAX_KEY_LENGTH = 128
        $longKey = str_repeat('k', 129);
        $header = "$longKey=value,short=ok";

        $result = $this->parser->parse($header);

        self::assertArrayNotHasKey($longKey, $result);
        self::assertSame('ok', $result['short']);
    }

    #[Test]
    public function acceptsKeyAtMaxKeyLength(): void
    {
        $key = str_repeat('k', 128);
        $header = "$key=value";

        $result = $this->parser->parse($header);

        self::assertSame('value', $result[$key]);
    }

    #[Test]
    public function skipsValueExceedingMaxValueLength(): void
    {
        // MAX_VALUE_LENGTH = 256 (after URL decoding)
        $longValue = str_repeat('v', 257);
        $header = "key=$longValue,ok=fine";

        $result = $this->parser->parse($header);

        self::assertArrayNotHasKey('key', $result);
        self::assertSame('fine', $result['ok']);
    }

    #[Test]
    public function acceptsValueAtMaxValueLength(): void
    {
        $value = str_repeat('v', 256);
        $header = "key=$value";

        $result = $this->parser->parse($header);

        self::assertSame($value, $result['key']);
    }

    #[Test]
    public function enforcesMaxEntriesLimit(): void
    {
        // MAX_ENTRIES = 180
        $entries = [];
        for ($i = 0; $i < 185; $i++) {
            $entries[] = "key$i=val$i";
        }
        $header = implode(',', $entries);

        $result = $this->parser->parse($header);

        self::assertCount(180, $result);
        self::assertArrayHasKey('key0', $result);
        self::assertArrayHasKey('key179', $result);
        self::assertArrayNotHasKey('key180', $result);
    }

    #[Test]
    public function skipsEmptyEntries(): void
    {
        $result = $this->parser->parse('key1=val1,,,,key2=val2');

        self::assertSame(['key1' => 'val1', 'key2' => 'val2'], $result);
    }

    #[Test]
    public function skipsEmptyKey(): void
    {
        $result = $this->parser->parse('=value,ok=yes');

        self::assertArrayNotHasKey('', $result);
        self::assertSame('yes', $result['ok']);
    }
}
