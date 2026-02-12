<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Http\Middleware;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Http\Middleware\ForumAuthMiddleware;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;

#[CoversClass(ForumAuthMiddleware::class)]
final class ForumAuthMiddlewareTest extends TestCase
{
    private ForumConfig $config;

    protected function setUp(): void
    {
        $this->config = new ForumConfig(allowGuestViewing: true);
    }

    private function makeRequest(string $method = 'GET', ?IdentityInterface $identity = null): ServerRequestInterface&Stub
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getAttribute')->willReturnCallback(function (string $name) use ($identity) {
            return $name === 'identity' ? $identity : null;
        });

        return $request;
    }

    private function makeHandler(?ResponseInterface $response = null): RequestHandlerInterface&Stub
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response ?? $this->createStub(ResponseInterface::class));

        return $handler;
    }

    private function makeIdentity(string $id = 'user-1', bool $authenticated = true): IdentityInterface&Stub
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn($id);
        $identity->method('isAuthenticated')->willReturn($authenticated);

        return $identity;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readOnlyMethodProvider(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
        yield 'OPTIONS' => ['OPTIONS'];
    }

    #[Test]
    #[DataProvider('readOnlyMethodProvider')]
    public function readOnlyRequestsAllowedForGuestsWhenEnabled(string $method): void
    {
        $middleware = new ForumAuthMiddleware($this->config);
        $request = $this->makeRequest($method);
        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->makeHandler($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function readOnlyRequestBlockedForGuestsWhenDisabled(): void
    {
        $config = new ForumConfig(allowGuestViewing: false);
        $middleware = new ForumAuthMiddleware($config);
        $request = $this->makeRequest('GET');
        $handler = $this->makeHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function writeRequestRequiresAuthentication(): void
    {
        $middleware = new ForumAuthMiddleware($this->config);
        $request = $this->makeRequest('POST');
        $handler = $this->makeHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function writeRequestWithUnauthenticatedIdentityReturns401(): void
    {
        $middleware = new ForumAuthMiddleware($this->config);
        $identity = $this->makeIdentity(authenticated: false);
        $request = $this->makeRequest('POST', $identity);
        $handler = $this->makeHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function writeRequestWithAuthenticatedIdentityProceeds(): void
    {
        $middleware = new ForumAuthMiddleware($this->config);
        $identity = $this->makeIdentity();
        $request = $this->makeRequest('POST', $identity);
        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->makeHandler($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function gateDenialReturns403(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $middleware = new ForumAuthMiddleware($this->config, gate: $gate);
        $identity = $this->makeIdentity();
        $request = $this->makeRequest('POST', $identity);
        $handler = $this->makeHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function gateAllowsAccessWhenPermitted(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        $middleware = new ForumAuthMiddleware($this->config, gate: $gate);
        $identity = $this->makeIdentity();
        $request = $this->makeRequest('POST', $identity);
        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->makeHandler($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function bannedUserReturns403(): void
    {
        $profile = ForumProfile::create('p-1', 'user-1');
        $banned = $profile->ban('Spam');

        $profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $profiles->method('findByUser')->willReturn($banned);

        $middleware = new ForumAuthMiddleware($this->config, profiles: $profiles);
        $identity = $this->makeIdentity('user-1');
        $request = $this->makeRequest('POST', $identity);
        $handler = $this->makeHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function expiredBanAllowsAccess(): void
    {
        $profile = ForumProfile::create('p-1', 'user-1');
        $banned = $profile->ban('Spam', new DateTimeImmutable('-1 hour'));

        $profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $profiles->method('findByUser')->willReturn($banned);

        $middleware = new ForumAuthMiddleware($this->config, profiles: $profiles);
        $identity = $this->makeIdentity('user-1');
        $request = $this->makeRequest('POST', $identity);
        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->makeHandler($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function activeBanWithFutureExpiryReturns403(): void
    {
        $profile = ForumProfile::create('p-1', 'user-1');
        $banned = $profile->ban('Spam', new DateTimeImmutable('+7 days'));

        $profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $profiles->method('findByUser')->willReturn($banned);

        $middleware = new ForumAuthMiddleware($this->config, profiles: $profiles);
        $identity = $this->makeIdentity('user-1');
        $request = $this->makeRequest('POST', $identity);
        $handler = $this->makeHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function userWithNoProfileCanProceed(): void
    {
        $profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $profiles->method('findByUser')->willReturn(null);

        $middleware = new ForumAuthMiddleware($this->config, profiles: $profiles);
        $identity = $this->makeIdentity('user-1');
        $request = $this->makeRequest('POST', $identity);
        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->makeHandler($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writeMethodProvider(): iterable
    {
        yield 'POST' => ['POST'];
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
        yield 'DELETE' => ['DELETE'];
    }

    #[Test]
    #[DataProvider('writeMethodProvider')]
    public function allWriteMethodsRequireAuth(string $method): void
    {
        $middleware = new ForumAuthMiddleware($this->config);
        $request = $this->makeRequest($method);
        $handler = $this->makeHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }
}
