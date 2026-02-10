<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Protocol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Exception\McpException;
use Pulsar\Extension\McpServer\Internal\Protocol\JsonRpcCodec;
use Pulsar\Extension\McpServer\Internal\Protocol\JsonRpcRequest;

#[CoversClass(JsonRpcCodec::class)]
#[CoversClass(JsonRpcRequest::class)]
final class JsonRpcCodecTest extends TestCase
{
    private JsonRpcCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new JsonRpcCodec();
    }

    #[Test]
    public function decodeValidRequest(): void
    {
        $json = '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"cursor":null}}';

        $request = $this->codec->decode($json);

        self::assertSame(1, $request->id);
        self::assertSame('tools/list', $request->method);
        self::assertSame(['cursor' => null], $request->params);
        self::assertFalse($request->isNotification());
    }

    #[Test]
    public function decodeNotification(): void
    {
        $json = '{"jsonrpc":"2.0","method":"notifications/initialized"}';

        $request = $this->codec->decode($json);

        self::assertNull($request->id);
        self::assertSame('notifications/initialized', $request->method);
        self::assertSame([], $request->params);
        self::assertTrue($request->isNotification());
    }

    #[Test]
    public function decodeThrowsOnMalformedJson(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32700);

        $this->codec->decode('{invalid json}}}');
    }

    #[Test]
    public function decodeThrowsOnMissingJsonrpc(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);

        $this->codec->decode('{"id":1,"method":"ping"}');
    }

    #[Test]
    public function decodeThrowsOnMissingMethod(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);

        $this->codec->decode('{"jsonrpc":"2.0","id":1}');
    }

    #[Test]
    public function encodeResultProducesCompactJson(): void
    {
        $result = $this->codec->encodeResult(1, ['status' => 'ok']);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('2.0', $decoded['jsonrpc']);
        self::assertSame(1, $decoded['id']);
        self::assertSame(['status' => 'ok'], $decoded['result']);
        self::assertStringEndsWith("\n", $result);
        // Compact: no pretty-print whitespace
        self::assertStringNotContainsString('  ', $result);
    }

    #[Test]
    public function encodeErrorIncludesErrorObject(): void
    {
        $result = $this->codec->encodeError(42, -32601, 'Method not found', ['detail' => 'unknown']);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('2.0', $decoded['jsonrpc']);
        self::assertSame(42, $decoded['id']);
        self::assertArrayHasKey('error', $decoded);

        /** @var array<string, mixed> $error */
        $error = $decoded['error'];
        self::assertSame(-32601, $error['code']);
        self::assertSame('Method not found', $error['message']);
        self::assertSame(['detail' => 'unknown'], $error['data']);
        self::assertStringEndsWith("\n", $result);
    }
}
