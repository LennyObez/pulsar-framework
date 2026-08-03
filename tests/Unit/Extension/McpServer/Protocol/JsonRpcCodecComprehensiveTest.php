<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Protocol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Exception\McpException;
use Pulsar\Extension\McpServer\Internal\Protocol\JsonRpcCodec;
use Pulsar\Extension\McpServer\Internal\Protocol\JsonRpcRequest;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Comprehensive tests for JsonRpcCodec covering all decode error paths,
 * encoding edge cases, and adversarial JSON inputs.
 */
#[CoversClass(JsonRpcCodec::class)]
#[CoversClass(JsonRpcRequest::class)]
final class JsonRpcCodecComprehensiveTest extends TestCase
{
    private JsonRpcCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new JsonRpcCodec();
    }

    // --- Decode error paths ---

    #[Test]
    public function decodeThrowsOnEmptyString(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32700);

        $this->codec->decode('');
    }

    #[Test]
    public function decodeThrowsOnNullJson(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);

        $this->codec->decode('null');
    }

    #[Test]
    public function decodeThrowsOnJsonNumber(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);

        $this->codec->decode('42');
    }

    #[Test]
    public function decodeThrowsOnJsonString(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);

        $this->codec->decode('"just a string"');
    }

    #[Test]
    public function decodeThrowsOnJsonArray(): void
    {
        // JSON-RPC batch is an array, but this codec doesn't support batch
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);

        $this->codec->decode('[{"jsonrpc":"2.0","id":1,"method":"ping"}]');
    }

    #[Test]
    public function decodeThrowsOnWrongJsonrpcVersion(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);
        $this->expectExceptionMessageIsOrContains('jsonrpc version');

        $this->codec->decode('{"jsonrpc":"1.0","id":1,"method":"ping"}');
    }

    #[Test]
    public function decodeThrowsOnMissingJsonrpcField(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);

        $this->codec->decode('{"id":1,"method":"ping"}');
    }

    #[Test]
    public function decodeThrowsOnEmptyMethod(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);
        $this->expectExceptionMessageIsOrContains('method');

        $this->codec->decode('{"jsonrpc":"2.0","id":1,"method":""}');
    }

    #[Test]
    public function decodeThrowsOnNonStringMethod(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);

        $this->codec->decode('{"jsonrpc":"2.0","id":1,"method":42}');
    }

    #[Test]
    public function decodeThrowsOnMissingMethod(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);

        $this->codec->decode('{"jsonrpc":"2.0","id":1}');
    }

    #[Test]
    public function decodeThrowsOnFloatId(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);
        $this->expectExceptionMessageIsOrContains('id must be');

        $this->codec->decode('{"jsonrpc":"2.0","id":1.5,"method":"ping"}');
    }

    #[Test]
    public function decodeThrowsOnBooleanId(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);

        $this->codec->decode('{"jsonrpc":"2.0","id":true,"method":"ping"}');
    }

    #[Test]
    public function decodeThrowsOnArrayId(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);

        $this->codec->decode('{"jsonrpc":"2.0","id":[1],"method":"ping"}');
    }

    #[Test]
    public function decodeThrowsOnObjectId(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);

        $this->codec->decode('{"jsonrpc":"2.0","id":{"nested":"id"},"method":"ping"}');
    }

    // --- Decode success paths ---

    #[Test]
    public function decodeWithStringId(): void
    {
        $request = $this->codec->decode('{"jsonrpc":"2.0","id":"abc-123","method":"ping"}');

        self::assertSame('abc-123', $request->id);
        self::assertSame('ping', $request->method);
        self::assertFalse($request->isNotification());
    }

    #[Test]
    public function decodeWithIntegerId(): void
    {
        $request = $this->codec->decode('{"jsonrpc":"2.0","id":42,"method":"ping"}');

        self::assertSame(42, $request->id);
    }

    #[Test]
    public function decodeWithZeroId(): void
    {
        $request = $this->codec->decode('{"jsonrpc":"2.0","id":0,"method":"ping"}');

        self::assertSame(0, $request->id);
        self::assertFalse($request->isNotification());
    }

    #[Test]
    public function decodeWithNullId(): void
    {
        $request = $this->codec->decode('{"jsonrpc":"2.0","id":null,"method":"ping"}');

        self::assertNull($request->id);
        self::assertTrue($request->isNotification());
    }

    #[Test]
    public function decodeNotificationWithoutIdField(): void
    {
        $request = $this->codec->decode('{"jsonrpc":"2.0","method":"notifications/initialized"}');

        self::assertNull($request->id);
        self::assertTrue($request->isNotification());
        self::assertSame('notifications/initialized', $request->method);
    }

    #[Test]
    public function decodeWithParams(): void
    {
        $json = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"test","arguments":{"filter":"*.php"}}}';
        $request = $this->codec->decode($json);

        self::assertSame('tools/call', $request->method);
        self::assertSame('test', $request->params['name']);

        /** @var array<string, mixed> $args */
        $args = $request->params['arguments'];
        self::assertSame('*.php', $args['filter']);
    }

    #[Test]
    public function decodeWithNullParams(): void
    {
        $json = '{"jsonrpc":"2.0","id":1,"method":"ping","params":null}';
        $request = $this->codec->decode($json);

        self::assertSame([], $request->params);
    }

    #[Test]
    public function decodeWithMissingParams(): void
    {
        $json = '{"jsonrpc":"2.0","id":1,"method":"ping"}';
        $request = $this->codec->decode($json);

        self::assertSame([], $request->params);
    }

    #[Test]
    public function decodeWithScalarParams(): void
    {
        // Params is a string (not an object) -- should default to empty
        $json = '{"jsonrpc":"2.0","id":1,"method":"ping","params":"not-an-object"}';
        $request = $this->codec->decode($json);

        self::assertSame([], $request->params);
    }

    // --- Encode result ---

    #[Test]
    public function encodeResultWithStringId(): void
    {
        $result = $this->codec->encodeResult('req-42', ['status' => 'ok']);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('2.0', $decoded['jsonrpc']);
        self::assertSame('req-42', $decoded['id']);
        self::assertSame(['status' => 'ok'], $decoded['result']);
        self::assertArrayNotHasKey('error', $decoded);
    }

    #[Test]
    public function encodeResultWithIntId(): void
    {
        $result = $this->codec->encodeResult(1, []);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $decoded['id']);
        self::assertSame([], $decoded['result']);
    }

    #[Test]
    public function encodeResultEndsWithNewline(): void
    {
        $result = $this->codec->encodeResult(1, []);

        self::assertStringEndsWith("\n", $result);
    }

    #[Test]
    public function encodeResultPreservesSlashes(): void
    {
        $result = $this->codec->encodeResult(1, ['url' => 'https://example.com/path']);

        // JSON_UNESCAPED_SLASHES means no escaped slashes
        self::assertStringContainsString('https://example.com/path', $result);
        self::assertStringNotContainsString('\\/', $result);
    }

    #[Test]
    public function encodeResultPreservesUnicode(): void
    {
        $result = $this->codec->encodeResult(1, ['name' => 'Rene']);

        // JSON_UNESCAPED_UNICODE means unicode chars are not escaped
        self::assertStringContainsString('Rene', $result);
    }

    // --- Encode error ---

    #[Test]
    public function encodeErrorWithNullId(): void
    {
        $result = $this->codec->encodeError(null, -32700, 'Parse error');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('2.0', $decoded['jsonrpc']);
        self::assertNull($decoded['id']);

        /** @var array<string, mixed> $error */
        $error = $decoded['error'];
        self::assertSame(-32700, $error['code']);
        self::assertSame('Parse error', $error['message']);
        self::assertArrayNotHasKey('data', $error);
    }

    #[Test]
    public function encodeErrorWithoutData(): void
    {
        $result = $this->codec->encodeError(1, -32600, 'Invalid request');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $error */
        $error = $decoded['error'];
        self::assertArrayNotHasKey('data', $error);
    }

    #[Test]
    public function encodeErrorWithData(): void
    {
        $result = $this->codec->encodeError(1, -32601, 'Not found', ['details' => 'method xyz']);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $error */
        $error = $decoded['error'];
        self::assertSame(['details' => 'method xyz'], $error['data']);
    }

    #[Test]
    public function encodeErrorEndsWithNewline(): void
    {
        $result = $this->codec->encodeError(1, -32600, 'Error');

        self::assertStringEndsWith("\n", $result);
    }

    #[Test]
    public function encodeErrorDoesNotContainResult(): void
    {
        $result = $this->codec->encodeError(1, -32600, 'Error');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('result', $decoded);
    }

    // --- Adversarial decode inputs ---

    #[Test]
    public function decodeThrowsOnDeeplyNestedJson(): void
    {
        // Build JSON with nesting depth > 64 (the decode limit)
        $json = str_repeat('{"a":', 70) . '1' . str_repeat('}', 70);
        $wrapped = '{"jsonrpc":"2.0","id":1,"method":"test","params":' . $json . '}';

        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32700);

        $this->codec->decode($wrapped);
    }

    #[Test]
    public function decodeHandlesExtraFieldsGracefully(): void
    {
        $json = '{"jsonrpc":"2.0","id":1,"method":"ping","extra_field":"ignored","another":42}';

        $request = $this->codec->decode($json);

        self::assertSame(1, $request->id);
        self::assertSame('ping', $request->method);
    }
}
