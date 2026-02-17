<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\LevelOfAssurance;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Auth\Middleware\LevelOfAssuranceMiddleware;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(LevelOfAssuranceMiddleware::class)]
final class LevelOfAssuranceMiddlewareTest extends TestCase
{
    #[Test]
    public function allowsLowLoaForLowRequirement(): void
    {
        $middleware = new LevelOfAssuranceMiddleware(LevelOfAssurance::Low);

        $request = new ServerRequest(method: 'GET', uri: '/public');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function rejectsUnauthenticatedUserForSubstantialRequirement(): void
    {
        $middleware = new LevelOfAssuranceMiddleware(LevelOfAssurance::Substantial);

        $request = new ServerRequest(method: 'GET', uri: '/secure');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function allowsAuthenticatedWithMfaForSubstantial(): void
    {
        $middleware = new LevelOfAssuranceMiddleware(LevelOfAssurance::Substantial);

        $identity = new Identity(
            id: 'user-1',
            displayName: 'Test User',
            twoFactorStatus: TwoFactorStatus::Verified,
        );

        $authManager = $this->createStub(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn($identity);

        $baseRequest = new ServerRequest(method: 'GET', uri: '/secure');
        $context = new SecurityContext($authManager, $baseRequest);
        $request = $baseRequest->withAttribute('_security_context', $context);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function rejectsAuthenticatedWithoutMfaForSubstantial(): void
    {
        $middleware = new LevelOfAssuranceMiddleware(LevelOfAssurance::Substantial);

        $identity = new Identity(
            id: 'user-1',
            displayName: 'Test User',
            twoFactorStatus: TwoFactorStatus::Disabled,
        );

        $authManager = $this->createStub(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn($identity);

        $baseRequest = new ServerRequest(method: 'GET', uri: '/secure');
        $context = new SecurityContext($authManager, $baseRequest);
        $request = $baseRequest->withAttribute('_security_context', $context);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function highLoaFromExplicitAttribute(): void
    {
        $middleware = new LevelOfAssuranceMiddleware(LevelOfAssurance::High);

        $request = new ServerRequest(method: 'GET', uri: '/critical')
            ->withAttribute('_loa_high', true);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function rejectsSubstantialForHighRequirement(): void
    {
        $middleware = new LevelOfAssuranceMiddleware(LevelOfAssurance::High);

        $identity = new Identity(
            id: 'user-1',
            displayName: 'Test User',
            twoFactorStatus: TwoFactorStatus::Verified,
        );

        $authManager = $this->createStub(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn($identity);

        $baseRequest = new ServerRequest(method: 'GET', uri: '/critical');
        $context = new SecurityContext($authManager, $baseRequest);
        $request = $baseRequest->withAttribute('_security_context', $context);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function jsonResponseForJsonAcceptHeader(): void
    {
        $middleware = new LevelOfAssuranceMiddleware(LevelOfAssurance::High);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/critical',
            headers: ['Accept' => 'application/json'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function resolveLevelReturnsLowForNoContext(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/test');

        $level = LevelOfAssuranceMiddleware::resolveLevel($request);

        self::assertSame(LevelOfAssurance::Low, $level);
    }

    #[Test]
    public function setsLoaAttributeOnPassingRequest(): void
    {
        $middleware = new LevelOfAssuranceMiddleware(LevelOfAssurance::Low);

        $request = new ServerRequest(method: 'GET', uri: '/public');

        $captured = null;
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            static function (ServerRequest $req) use (&$captured) {
                $captured = $req->getAttribute(LevelOfAssuranceMiddleware::LOA_ATTRIBUTE);
                return Response::text('OK');
            },
        );

        $middleware->process($request, $handler);

        self::assertSame(LevelOfAssurance::Low, $captured);
    }
}
