<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Broadcasting\BroadcastAuthController;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\WebSocket\ChannelAuthorizerInterface;

#[CoversClass(BroadcastAuthController::class)]
final class BroadcastAuthControllerTest extends TestCase
{
    #[Test]
    public function publicChannelAuthReturnsSuccess(): void
    {
        $authorizer = $this->createStub(ChannelAuthorizerInterface::class);
        $controller = new BroadcastAuthController($authorizer);

        $request = $this->buildRequest('updates', 'sock-1');
        $response = $controller->authenticate($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('updates', $body['channel']);
        self::assertTrue($body['auth']);
    }

    #[Test]
    public function privateChannelAuthGranted(): void
    {
        $authorizer = $this->createStub(ChannelAuthorizerInterface::class);
        $authorizer->method('authorizePrivate')->willReturn(true);

        $controller = new BroadcastAuthController($authorizer);

        $request = $this->buildRequest('private-orders', 'sock-2');
        $response = $controller->authenticate($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('private-orders', $body['channel']);
        self::assertTrue($body['auth']);
    }

    #[Test]
    public function privateChannelAuthDenied(): void
    {
        $authorizer = $this->createStub(ChannelAuthorizerInterface::class);
        $authorizer->method('authorizePrivate')->willReturn(false);

        $controller = new BroadcastAuthController($authorizer);

        $request = $this->buildRequest('private-secret', 'sock-3');
        $response = $controller->authenticate($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Forbidden', $body['error']);
    }

    #[Test]
    public function presenceChannelAuthGranted(): void
    {
        $authorizer = $this->createStub(ChannelAuthorizerInterface::class);
        $authorizer->method('authorizePresence')->willReturn([
            'id' => 42,
            'name' => 'Alice',
        ]);

        $controller = new BroadcastAuthController($authorizer);

        $request = $this->buildRequest('presence-chat', 'sock-4');
        $response = $controller->authenticate($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('presence-chat', $body['channel']);
        self::assertTrue($body['auth']);
        self::assertSame(['id' => 42, 'name' => 'Alice'], $body['user_info']);
    }

    #[Test]
    public function presenceChannelAuthDenied(): void
    {
        $authorizer = $this->createStub(ChannelAuthorizerInterface::class);
        $authorizer->method('authorizePresence')->willReturn(null);

        $controller = new BroadcastAuthController($authorizer);

        $request = $this->buildRequest('presence-vip', 'sock-5');
        $response = $controller->authenticate($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Forbidden', $body['error']);
    }

    #[Test]
    #[DataProvider('invalidInputProvider')]
    public function invalidInputReturns400(
        mixed $channelName,
        mixed $socketId,
    ): void {
        $authorizer = $this->createStub(ChannelAuthorizerInterface::class);
        $controller = new BroadcastAuthController($authorizer);

        $post = [];
        if ($channelName !== null) {
            $post['channel_name'] = $channelName;
        }
        if ($socketId !== null) {
            $post['socket_id'] = $socketId;
        }

        $request = new Request(
            method: Method::POST,
            uri: '/broadcasting/auth',
            path: '/broadcasting/auth',
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
            post: $post,
        );

        $response = $controller->authenticate($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $body);
        self::assertIsString($body['error']);
        self::assertStringContainsString('Missing', $body['error']);
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function invalidInputProvider(): iterable
    {
        yield 'both missing' => [null, null];
        yield 'channel missing' => [null, 'sock-1'];
        yield 'socket missing' => ['private-orders', null];
        yield 'channel empty string' => ['', 'sock-1'];
        yield 'socket empty string' => ['private-orders', ''];
        yield 'both empty strings' => ['', ''];
        yield 'channel is int' => [42, 'sock-1'];
        yield 'socket is array' => ['private-orders', ['sock-1']];
    }

    private function buildRequest(string $channelName, string $socketId): Request
    {
        return new Request(
            method: Method::POST,
            uri: '/broadcasting/auth',
            path: '/broadcasting/auth',
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
            post: [
                'channel_name' => $channelName,
                'socket_id' => $socketId,
            ],
        );
    }
}
