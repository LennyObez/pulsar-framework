<?php

declare(strict_types=1);

namespace Pulsar\Tests\Fuzz;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\Request;

#[CoversClass(ServerRequest::class)]
#[CoversClass(Request::class)]
#[Group('fuzz')]
final class JsonParsingFuzzTest extends TestCase
{
    #[Test]
    public function deeplyNestedObjectsDoNotCauseErrors(): void
    {
        $depth = 128;
        $json = str_repeat('{"a":', $depth) . '1' . str_repeat('}', $depth);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/',
            headers: ['Content-Type' => 'application/json'],
            body: $json,
        );

        $result = $request->json();
        self::assertIsArray($result);
    }

    #[Test]
    public function extremelyDeepNestingExceeding512LevelsIsHandledGracefully(): void
    {
        $depth = 600;
        $json = str_repeat('{"a":', $depth) . '1' . str_repeat('}', $depth);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/',
            headers: ['Content-Type' => 'application/json'],
            body: $json,
        );

        // json_decode with depth=512 should fail gracefully for >512 levels
        $result = $request->json();
        self::assertIsArray($result);
    }

    #[Test]
    public function extremelyLargeNumbersAreHandled(): void
    {
        $numbers = [
            '{"n": 999999999999999999999999999999999999999999999999999}',
            '{"n": -999999999999999999999999999999999999999999999999999}',
            '{"n": 1e308}',
            '{"n": 1e-308}',
            '{"n": 1e999}',
            '{"n": 0.000000000000000000000000000000000000000000000001}',
            '{"n": ' . str_repeat('9', 1000) . '}',
        ];

        foreach ($numbers as $json) {
            $request = new ServerRequest(
                method: 'POST',
                uri: '/',
                headers: ['Content-Type' => 'application/json'],
                body: $json,
            );

            $result = $request->json();
            self::assertIsArray($result);
        }
    }

    #[Test]
    public function unicodeEscapeSequencesAreHandled(): void
    {
        $payloads = [
            '{"key": "\\u0000"}',
            '{"key": "\\u0041"}',
            '{"key": "\\uD800"}',        // lone surrogate
            '{"key": "\\uDFFF"}',        // lone surrogate
            '{"key": "\\uD83D\\uDE00"}', // emoji pair
            '{"key": "\\u00E9"}',        // e-acute
            '{"key": "' . str_repeat('\\u0041', 1000) . '"}',
        ];

        foreach ($payloads as $json) {
            $request = new ServerRequest(
                method: 'POST',
                uri: '/',
                headers: ['Content-Type' => 'application/json'],
                body: $json,
            );

            $result = $request->json();
            self::assertIsArray($result);
        }
    }

    #[Test]
    public function malformedJsonStructuresAreHandledGracefully(): void
    {
        $malformed = [
            '{',
            '}',
            '[',
            ']',
            '{{}}',
            '{key: value}',
            "{'key': 'value'}",
            '{"key": undefined}',
            '{"key": NaN}',
            '{"key": Infinity}',
            '{"key": }',
            '{: "value"}',
            '{"key": "value",}',
            '{"key": "value" "key2": "value2"}',
            '',
            ' ',
            'null',
            'true',
            'false',
            '42',
            '"just a string"',
            '{"a": "b"} {"c": "d"}',
            '/* comment */ {"key": "value"}',
        ];

        foreach ($malformed as $json) {
            $request = new ServerRequest(
                method: 'POST',
                uri: '/',
                headers: ['Content-Type' => 'application/json'],
                body: $json,
            );

            $result = $request->json();
            self::assertIsArray($result);
        }
    }

    #[Test]
    public function bomPrefixedJsonIsHandled(): void
    {
        $boms = [
            "\xEF\xBB\xBF",     // UTF-8 BOM
            "\xFF\xFE",          // UTF-16 LE BOM
            "\xFE\xFF",          // UTF-16 BE BOM
            "\x00\x00\xFE\xFF", // UTF-32 BE BOM
        ];

        foreach ($boms as $bom) {
            $json = $bom . '{"key": "value"}';

            $request = new ServerRequest(
                method: 'POST',
                uri: '/',
                headers: ['Content-Type' => 'application/json'],
                body: $json,
            );

            $result = $request->json();
            self::assertIsArray($result);
        }
    }

    #[Test]
    public function veryLargeJsonPayloadsAreHandled(): void
    {
        // Build a JSON object with many keys
        $parts = [];
        for ($i = 0; $i < 1000; $i++) {
            $parts[] = '"key' . $i . '": "' . str_repeat('v', 100) . '"';
        }
        $json = '{' . implode(',', $parts) . '}';

        $request = new ServerRequest(
            method: 'POST',
            uri: '/',
            headers: ['Content-Type' => 'application/json'],
            body: $json,
        );

        $result = $request->json();
        self::assertIsArray($result);
        self::assertArrayHasKey('key0', $result);
    }

    #[Test]
    public function duplicateKeysAreHandled(): void
    {
        $json = '{"key": "first", "key": "second", "key": "third"}';

        $request = new ServerRequest(
            method: 'POST',
            uri: '/',
            headers: ['Content-Type' => 'application/json'],
            body: $json,
        );

        $result = $request->json();
        self::assertIsArray($result);
        // PHP's json_decode takes the last value for duplicate keys
        self::assertSame('third', $result['key']);
    }

    #[Test]
    public function pulsarRequestJsonParsingHandlesSamePayloads(): void
    {
        $payloads = [
            '{"key": "value"}',
            '[]',
            'null',
            '""',
            '{invalid',
            '',
        ];

        foreach ($payloads as $body) {
            $request = new Request(
                method: Method::POST,
                uri: '/',
                path: '/',
                queryString: '',
                headers: new HeaderBag(['Content-Type' => 'application/json']),
                body: $body,
            );

            $result = $request->json();
            self::assertIsArray($result);
        }
    }

    #[Test]
    public function jsonWithSpecialStringValuesIsHandled(): void
    {
        $payloads = [
            '{"key": "' . str_repeat('\\n', 500) . '"}',
            '{"key": "' . str_repeat('\\t', 500) . '"}',
            '{"key": "' . str_repeat('\\\\', 500) . '"}',
        ];

        foreach ($payloads as $json) {
            $request = new ServerRequest(
                method: 'POST',
                uri: '/',
                headers: ['Content-Type' => 'application/json'],
                body: $json,
            );

            $result = $request->json();
            self::assertIsArray($result);
        }
    }
}
