<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Subscriptions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Subscriptions\Http\Middleware\SubscriptionTokenGuard;
use Pulsar\Http\Message\Response;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class SubscriptionTokenGuardTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function jsonResponse(Response|\Psr\Http\Message\ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> */
        return $decoded;
    }

    #[Test]
    public function passesAuthenticatedRequestToHandler(): void
    {
        $guard = new SubscriptionTokenGuard(
            static fn(string $token): ?string => $token === 'valid-token' ? 'user-42' : null,
        );

        $expectedResponse = Response::json(['ok' => true]);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Bearer valid-token');

        $authenticatedRequest = $this->createStub(ServerRequestInterface::class);
        $request->method('withAttribute')->willReturn($authenticatedRequest);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $guard->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenAuthorizationHeaderIsMissing(): void
    {
        $guard = new SubscriptionTokenGuard(
            static fn(string $token): string => 'user-1',
        );

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $guard->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Missing or malformed', $error);
    }

    #[Test]
    public function returns401WhenAuthorizationHeaderIsNotBearer(): void
    {
        $guard = new SubscriptionTokenGuard(
            static fn(string $token): string => 'user-1',
        );

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Basic dXNlcjpwYXNz');

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $guard->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Missing or malformed', $error);
    }

    #[Test]
    public function returns401WhenTokenResolverReturnsNull(): void
    {
        $guard = new SubscriptionTokenGuard(
            static fn(string $token): ?string => null,
        );

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Bearer invalid-token');
        $request->method('withAttribute')->willReturn($request);

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $guard->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Invalid or expired', $error);
    }

    #[Test]
    public function returns401WhenTokenResolverReturnsEmptyString(): void
    {
        $guard = new SubscriptionTokenGuard(
            static fn(string $token): string => '',
        );

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Bearer some-token');
        $request->method('withAttribute')->willReturn($request);

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $guard->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Invalid or expired', $error);
    }

    #[Test]
    public function acceptsBearerTokenCaseInsensitively(): void
    {
        $guard = new SubscriptionTokenGuard(
            static fn(string $token): string => 'user-99',
        );

        $expectedResponse = Response::json(['ok' => true]);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('bearer my-token');
        $request->method('withAttribute')->willReturn($request);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $guard->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedHeaders(): iterable
    {
        yield 'bearer-without-space' => ['Bearertoken123'];
        yield 'just-bearer-keyword' => ['Bearer'];
    }

    #[Test]
    #[DataProvider('provideMalformedHeaders')]
    public function returns401ForMalformedBearerHeader(string $headerValue): void
    {
        $guard = new SubscriptionTokenGuard(
            static fn(string $token): string => 'user-1',
        );

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn($headerValue);

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $guard->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }
}
