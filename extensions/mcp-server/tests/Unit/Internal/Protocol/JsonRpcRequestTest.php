<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Internal\Protocol;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Internal\Protocol\JsonRpcRequest;

final class JsonRpcRequestTest extends TestCase
{
    #[Test]
    public function isNotificationReturnsTrueForNullId(): void
    {
        $request = new JsonRpcRequest(null, 'notifications/initialized', []);

        self::assertTrue($request->isNotification());
    }

    #[Test]
    public function isNotificationReturnsFalseForIntId(): void
    {
        $request = new JsonRpcRequest(1, 'tools/list', []);

        self::assertFalse($request->isNotification());
    }

    #[Test]
    public function isNotificationReturnsFalseForStringId(): void
    {
        $request = new JsonRpcRequest('req-1', 'ping', []);

        self::assertFalse($request->isNotification());
    }

    #[Test]
    public function constructsWithParams(): void
    {
        $request = new JsonRpcRequest(42, 'tools/call', ['name' => 'read_routes']);

        self::assertSame(42, $request->id);
        self::assertSame('tools/call', $request->method);
        self::assertSame(['name' => 'read_routes'], $request->params);
    }
}
