<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Broadcasting\BroadcastAuthController;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\WebSocket\ChannelAuthorizerInterface;
use Pulsar\WebSocket\WebSocketConnection;

use function str_repeat;

#[CoversClass(BroadcastAuthController::class)]
final class BroadcastAuthControllerTest extends TestCase
{
    #[Test]
    public function publicChannelAuthReturnsSuccessWithoutAnyIdentity(): void
    {
        // Arrange — no identity attribute at all: public channels stay auth-free
        $authorizer = $this->createStub(ChannelAuthorizerInterface::class);
        $controller = new BroadcastAuthController($authorizer);

        // Act
        $response = $controller->authenticate($this->buildRequest('updates', 'sock-1'));

        // Assert
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('updates', $body['channel']);
        self::assertTrue($body['auth']);
    }

    #[Test]
    public function privateChannelAuthGrantedForAuthenticatedIdentity(): void
    {
        // Arrange
        $authorizer = $this->createStub(ChannelAuthorizerInterface::class);
        $authorizer->method('authorizePrivate')->willReturn(true);
        $controller = new BroadcastAuthController($authorizer);

        // Act
        $response = $controller->authenticate(
            $this->buildRequest('private-orders', 'sock-2', self::user('user-7')),
        );

        // Assert
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('private-orders', $body['channel']);
        self::assertTrue($body['auth']);
    }

    #[Test]
    public function privateChannelAuthDeniedByAuthorizerReturns403(): void
    {
        // Arrange — authenticated, but the app's authorizer says no
        $authorizer = $this->createStub(ChannelAuthorizerInterface::class);
        $authorizer->method('authorizePrivate')->willReturn(false);
        $controller = new BroadcastAuthController($authorizer);

        // Act
        $response = $controller->authenticate(
            $this->buildRequest('private-secret', 'sock-3', self::user('user-7')),
        );

        // Assert
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Forbidden', $body['error']);
    }

    #[Test]
    public function presenceChannelAuthGrantedForAuthenticatedIdentity(): void
    {
        // Arrange
        $authorizer = $this->createStub(ChannelAuthorizerInterface::class);
        $authorizer->method('authorizePresence')->willReturn([
            'id' => 42,
            'name' => 'Alice',
        ]);
        $controller = new BroadcastAuthController($authorizer);

        // Act
        $response = $controller->authenticate(
            $this->buildRequest('presence-chat', 'sock-4', self::user('user-42')),
        );

        // Assert
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('presence-chat', $body['channel']);
        self::assertTrue($body['auth']);
        self::assertSame(['id' => 42, 'name' => 'Alice'], $body['user_info']);
    }

    #[Test]
    public function presenceChannelAuthDeniedByAuthorizerReturns403(): void
    {
        // Arrange
        $authorizer = $this->createStub(ChannelAuthorizerInterface::class);
        $authorizer->method('authorizePresence')->willReturn(null);
        $controller = new BroadcastAuthController($authorizer);

        // Act
        $response = $controller->authenticate(
            $this->buildRequest('presence-vip', 'sock-5', self::user('user-9')),
        );

        // Assert
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Forbidden', $body['error']);
    }

    #[Test]
    #[DataProvider('protectedChannelProvider')]
    public function protectedChannelWithoutIdentityReturns401AndNeverInvokesTheAuthorizer(
        string $channelName,
    ): void {
        // Arrange — no identity attribute (endpoint not behind auth middleware)
        $authorizer = $this->createMock(ChannelAuthorizerInterface::class);
        $authorizer->expects(self::never())->method('authorizePrivate');
        $authorizer->expects(self::never())->method('authorizePresence');
        $controller = new BroadcastAuthController($authorizer);

        // Act
        $response = $controller->authenticate($this->buildRequest($channelName, 'sock-6'));

        // Assert — deny-by-default BEFORE any authorization logic runs
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Unauthenticated', $body['error']);
    }

    #[Test]
    #[DataProvider('protectedChannelProvider')]
    public function protectedChannelWithAnonymousIdentityReturns401(
        string $channelName,
    ): void {
        // Arrange — the auth middleware ran but resolved an anonymous identity
        $authorizer = $this->createMock(ChannelAuthorizerInterface::class);
        $authorizer->expects(self::never())->method('authorizePrivate');
        $authorizer->expects(self::never())->method('authorizePresence');
        $controller = new BroadcastAuthController($authorizer);

        // Act
        $response = $controller->authenticate(
            $this->buildRequest($channelName, 'sock-7', new AnonymousIdentity()),
        );

        // Assert
        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function protectedChannelProvider(): iterable
    {
        yield 'private channel' => ['private-orders'];
        yield 'presence channel' => ['presence-chat'];
    }

    #[Test]
    public function authorizerReceivesAConnectionAuthenticatedAsTheRequestIdentity(): void
    {
        // Arrange — capture the connection the authorizer actually decides with
        $captured = null;
        $authorizer = $this->createStub(ChannelAuthorizerInterface::class);
        $authorizer->method('authorizePrivate')->willReturnCallback(
            static function (string $channel, WebSocketConnection $connection) use (&$captured): bool {
                $captured = $connection;

                return true;
            },
        );
        $controller = new BroadcastAuthController($authorizer);

        // Act
        $controller->authenticate(
            $this->buildRequest('private-orders', '123.456', self::user('user-42')),
        );

        // Assert — the principal is the middleware-resolved identity, bound to
        // the client's socket id; not an anonymous ephemeral connection
        self::assertInstanceOf(WebSocketConnection::class, $captured);
        self::assertSame('user-42', $captured->userId());
        self::assertTrue($captured->isAuthenticated());
        self::assertSame('123.456', $captured->id);
    }

    #[Test]
    #[DataProvider('malformedSocketIdProvider')]
    public function malformedSocketIdReturns400BeforeAnyAuthorization(string $socketId): void
    {
        // Arrange
        $authorizer = $this->createMock(ChannelAuthorizerInterface::class);
        $authorizer->expects(self::never())->method('authorizePrivate');
        $authorizer->expects(self::never())->method('authorizePresence');
        $controller = new BroadcastAuthController($authorizer);

        // Act
        $response = $controller->authenticate(
            $this->buildRequest('private-orders', $socketId, self::user('user-7')),
        );

        // Assert
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Invalid socket_id format', $body['error']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedSocketIdProvider(): iterable
    {
        yield 'embedded newline' => ["sock\nX-Injected: 1"];
        yield 'leading separator' => ['-leading'];
        yield 'path traversal shape' => ['../etc/passwd'];
        yield 'whitespace inside' => ['sock 1'];
        yield 'over 64 chars' => ['a' . str_repeat('b', 64)];
        yield 'braces' => ['sock{1}'];
    }

    #[Test]
    #[DataProvider('validSocketIdProvider')]
    public function commonTransportSocketIdFormatsAreAccepted(string $socketId): void
    {
        // Arrange — public channel: reaches the success path when the socket id
        // passes format validation
        $authorizer = $this->createStub(ChannelAuthorizerInterface::class);
        $controller = new BroadcastAuthController($authorizer);

        // Act
        $response = $controller->authenticate($this->buildRequest('updates', $socketId));

        // Assert
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validSocketIdProvider(): iterable
    {
        yield 'pusher style' => ['123.456'];
        yield 'dashed' => ['conn-99'];
        yield 'mixed separators' => ['a1B2_c3:4.5'];
        yield 'single char' => ['x'];
        yield 'exactly 64 chars' => ['a' . str_repeat('b', 63)];
    }

    #[Test]
    #[DataProvider('mixedCaseChannelProvider')]
    public function mixedCasePrivateOrPresenceChannelIsRejectedNotAuthorizedAsPublic(
        string $channelName,
    ): void {
        // Without lowercase validation, a mixed-case "private-"/"presence-"
        // prefix bypasses the case-sensitive prefix checks and falls through to
        // the public-channel path, returning auth:true with no authorization
        // call. The guard must reject it with 400 instead.
        $authorizer = $this->createStub(ChannelAuthorizerInterface::class);
        $controller = new BroadcastAuthController($authorizer);

        $request = $this->buildRequest($channelName, 'sock-mixed');
        $response = $controller->authenticate($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $body);
        self::assertArrayNotHasKey('auth', $body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mixedCaseChannelProvider(): iterable
    {
        yield 'capitalized private prefix' => ['Private-orders'];
        yield 'uppercase private prefix' => ['PRIVATE-orders'];
        yield 'capitalized presence prefix' => ['Presence-chat'];
        yield 'mixed-case public channel' => ['Updates'];
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

    private static function user(string $id): Identity
    {
        return new Identity(id: $id, displayName: 'Test User');
    }

    private function buildRequest(
        string $channelName,
        string $socketId,
        ?IdentityInterface $identity = null,
    ): Request {
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
            attributes: $identity !== null ? ['identity' => $identity] : [],
        );
    }
}
