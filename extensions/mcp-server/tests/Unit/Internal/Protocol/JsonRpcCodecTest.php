<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Internal\Protocol;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Exception\McpException;
use Pulsar\Extension\McpServer\Internal\Protocol\JsonRpcCodec;

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
        $json = '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}';

        $request = $this->codec->decode($json);

        self::assertSame(1, $request->id);
        self::assertSame('tools/list', $request->method);
        self::assertSame([], $request->params);
    }

    #[Test]
    public function decodeNotificationHasNullId(): void
    {
        $json = '{"jsonrpc":"2.0","method":"notifications/initialized","params":{}}';

        $request = $this->codec->decode($json);

        self::assertNull($request->id);
        self::assertTrue($request->isNotification());
    }

    #[Test]
    public function decodeThrowsOnInvalidJson(): void
    {
        $this->expectException(McpException::class);
        $this->codec->decode('not json');
    }

    #[Test]
    public function decodeThrowsOnMissingJsonrpcVersion(): void
    {
        $this->expectException(McpException::class);
        $this->codec->decode('{"id":1,"method":"ping"}');
    }

    #[Test]
    public function decodeThrowsOnWrongJsonrpcVersion(): void
    {
        $this->expectException(McpException::class);
        $this->codec->decode('{"jsonrpc":"1.0","id":1,"method":"ping"}');
    }

    #[Test]
    public function decodeThrowsOnMissingMethod(): void
    {
        $this->expectException(McpException::class);
        $this->codec->decode('{"jsonrpc":"2.0","id":1}');
    }

    #[Test]
    public function decodeThrowsOnEmptyMethod(): void
    {
        $this->expectException(McpException::class);
        $this->codec->decode('{"jsonrpc":"2.0","id":1,"method":""}');
    }

    #[Test]
    public function decodeThrowsOnNonObjectInput(): void
    {
        $this->expectException(McpException::class);
        $this->codec->decode('"just a string"');
    }

    #[Test]
    public function decodeAcceptsStringId(): void
    {
        $json = '{"jsonrpc":"2.0","id":"req-1","method":"ping"}';

        $request = $this->codec->decode($json);

        self::assertSame('req-1', $request->id);
    }

    #[Test]
    public function encodeResultProducesValidJson(): void
    {
        $encoded = $this->codec->encodeResult(1, ['key' => 'value']);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($encoded, true);

        self::assertSame('2.0', $decoded['jsonrpc']);
        self::assertSame(1, $decoded['id']);
        self::assertSame(['key' => 'value'], $decoded['result']);
    }

    #[Test]
    public function encodeResultEndsWithNewline(): void
    {
        $encoded = $this->codec->encodeResult('id', []);

        self::assertStringEndsWith("\n", $encoded);
    }

    #[Test]
    public function encodeErrorProducesValidJson(): void
    {
        $encoded = $this->codec->encodeError(1, -32600, 'Invalid request');
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($encoded, true);

        self::assertSame('2.0', $decoded['jsonrpc']);
        self::assertSame(1, $decoded['id']);
        /** @var array<string, mixed> $error */
        $error = $decoded['error'];
        self::assertSame(-32600, $error['code']);
        self::assertSame('Invalid request', $error['message']);
        self::assertArrayNotHasKey('data', $error);
    }

    #[Test]
    public function encodeErrorIncludesDataWhenProvided(): void
    {
        $encoded = $this->codec->encodeError(null, -32700, 'Parse', ['detail' => 'unexpected token']);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($encoded, true);

        self::assertNull($decoded['id']);
        /** @var array<string, mixed> $error */
        $error = $decoded['error'];
        /** @var array<string, mixed> $data */
        $data = $error['data'];
        self::assertSame('unexpected token', $data['detail']);
    }
}
