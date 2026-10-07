<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Protocol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Internal\Protocol\JsonRpcRequest;

#[CoversClass(JsonRpcRequest::class)]
final class JsonRpcRequestTest extends TestCase
{
    #[Test]
    public function requestWithIntId(): void
    {
        $request = new JsonRpcRequest(id: 42, method: 'tools/list', params: ['cursor' => null]);

        self::assertSame(42, $request->id);
        self::assertSame('tools/list', $request->method);
        self::assertSame(['cursor' => null], $request->params);
        self::assertFalse($request->isNotification());
    }

    #[Test]
    public function requestWithStringId(): void
    {
        $request = new JsonRpcRequest(id: 'req-abc-123', method: 'tools/call', params: ['name' => 'test']);

        self::assertSame('req-abc-123', $request->id);
        self::assertFalse($request->isNotification());
    }

    #[Test]
    public function notificationHasNullId(): void
    {
        $request = new JsonRpcRequest(id: null, method: 'notifications/initialized', params: []);

        self::assertNull($request->id);
        self::assertTrue($request->isNotification());
    }

    #[Test]
    public function emptyParams(): void
    {
        $request = new JsonRpcRequest(id: 1, method: 'ping', params: []);

        self::assertSame([], $request->params);
    }
}
