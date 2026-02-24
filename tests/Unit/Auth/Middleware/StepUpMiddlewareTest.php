<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Middleware\StepUpMiddleware;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;
use Pulsar\Security\Session\SessionInterface;

use function json_decode;

#[CoversClass(StepUpMiddleware::class)]
final class StepUpMiddlewareTest extends TestCase
{
    /** @var SessionInterface&Stub */
    private SessionInterface $session;

    protected function setUp(): void
    {
        $this->session = $this->createStub(SessionInterface::class);
    }

    #[Test]
    public function missingStepUpTimestampReturns403(): void
    {
        $middleware = new StepUpMiddleware($this->session, timeoutMinutes: 15);

        $identity = new Identity('user-1', 'Test User', ['user']);
        $request = $this->createRequestWithIdentity($identity);

        $this->session->method('get')->willReturn(null);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function expiredStepUpReturns403(): void
    {
        $middleware = new StepUpMiddleware($this->session, timeoutMinutes: 15);

        $identity = new Identity('user-1', 'Test User', ['user']);
        $request = $this->createRequestWithIdentity($identity);

        // Set step-up 20 minutes ago (expired for 15-minute timeout)
        $this->session->method('get')->willReturn(time() - (20 * 60));

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function freshStepUpPassesThrough(): void
    {
        $middleware = new StepUpMiddleware($this->session, timeoutMinutes: 15);

        $identity = new Identity('user-1', 'Test User', ['user']);
        $request = $this->createRequestWithIdentity($identity);

        // Set step-up 5 minutes ago (fresh for 15-minute timeout)
        $this->session->method('get')->willReturn(time() - (5 * 60));

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('OK', (string) $response->getBody());
    }

    #[Test]
    public function markStepUpAuthenticatedSetsSessionValue(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())
            ->method('set')
            ->with('_pulsar_step_up[user-1]', self::isInt());

        StepUpMiddleware::markStepUpAuthenticated($session, 'user-1');
    }

    #[Test]
    public function differentIdentitiesDontInheritStepUp(): void
    {
        $middleware = new StepUpMiddleware($this->session, timeoutMinutes: 15);

        $identity = new Identity('user-2', 'Other User', ['user']);
        $request = $this->createRequestWithIdentity($identity);

        // Session has step-up for user-1 but not user-2
        $this->session->method('get')
            ->willReturnCallback(fn(string $key) => match ($key) {
                '_pulsar_step_up[user-1]' => time(),
                default => null,
            });

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function jsonRequestGetsJsonResponse(): void
    {
        $middleware = new StepUpMiddleware($this->session, timeoutMinutes: 15);

        $identity = new Identity('user-1', 'Test User', ['user']);
        $request = $this->createRequestWithIdentity($identity, headers: ['Accept' => 'application/json']);

        $this->session->method('get')->willReturn(null);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('Step-up authentication required', (string) $response->getBody());
    }

    #[Test]
    public function returns403WhenNoSecurityContext(): void
    {
        /** @var SessionInterface&Stub $session */
        $session = $this->createStub(SessionInterface::class);
        $middleware = new StepUpMiddleware($session);

        $request = new ServerRequest(method: 'POST', uri: '/wire-transfer');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertSame('Step-up authentication required', (string) $response->getBody());
    }

    #[Test]
    public function returns403JsonWhenNoSecurityContextAndAcceptJson(): void
    {
        /** @var SessionInterface&Stub $session */
        $session = $this->createStub(SessionInterface::class);
        $middleware = new StepUpMiddleware($session);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/wire-transfer',
            headers: ['Accept' => 'application/json'],
        );
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Step-up authentication required', $body['error']);
        self::assertSame(403, $body['status']);
    }

    #[Test]
    public function returns403WhenIdentityIsAnonymous(): void
    {
        /** @var SessionInterface&Stub $session */
        $session = $this->createStub(SessionInterface::class);
        $middleware = new StepUpMiddleware($session);

        $authManager = $this->createStub(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn(new AnonymousIdentity());

        $request = new ServerRequest(method: 'POST', uri: '/sensitive-action');
        $securityContext = new SecurityContext($authManager, $request);
        $request = $request->withAttribute('_security_context', $securityContext);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    /**
     * @param array<string, string> $headers
     */
    private function createRequestWithIdentity(
        IdentityInterface $identity,
        array $headers = [],
    ): ServerRequest {
        $authManager = $this->createStub(AuthManagerInterface::class);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/test',
            headers: $headers,
        );

        $authManager->method('authenticate')->willReturn($identity);
        $securityContext = new SecurityContext($authManager, $request);

        return new ServerRequest(
            method: 'GET',
            uri: '/test',
            headers: $headers,
            attributes: ['_security_context' => $securityContext],
        );
    }
}
