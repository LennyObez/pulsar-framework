<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\AuthenticationState;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Auth\Middleware\AuthenticationMiddleware;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;

#[CoversClass(AuthenticationMiddleware::class)]
#[CoversClass(AuthenticationState::class)]
final class AuthenticationMiddlewareTest extends TestCase
{
    #[Test]
    public function attachesSecurityContextAttributeToRequest(): void
    {
        $authManager = $this->createStub(AuthManagerInterface::class);
        $state = new AuthenticationState();
        $middleware = new AuthenticationMiddleware($authManager, $state);

        $capturedRequest = $this->handle($middleware, new ServerRequest(method: 'GET', uri: '/test'));

        $securityContext = $capturedRequest->getAttribute('_security_context');
        self::assertInstanceOf(SecurityContext::class, $securityContext);
        self::assertSame(
            $state->context(),
            $securityContext,
            'The attribute must be the SAME context the holder carries, or the two channels memoise separately '
            . 'and an `auth`-guarded bound route authenticates twice.',
        );
    }

    #[Test]
    public function publishesTheSecurityContextIntoTheHolder(): void
    {
        // The holder is the channel the model binding layer reads. Attaching the
        // context to the request without publishing it here would leave that
        // layer with no caller at all — every bound route refused under a
        // regulated preset.
        $authManager = $this->createStub(AuthManagerInterface::class);
        $state = new AuthenticationState();

        self::assertNull($state->context(), 'Nothing is established before the middleware runs.');

        $this->handle(new AuthenticationMiddleware($authManager, $state), new ServerRequest(method: 'GET', uri: '/test'));

        self::assertInstanceOf(SecurityContext::class, $state->context());
    }

    #[Test]
    public function attachesAnonymousIdentityAttributeToRequest(): void
    {
        $authManager = $this->createStub(AuthManagerInterface::class);
        $middleware = new AuthenticationMiddleware($authManager, new AuthenticationState());

        $capturedRequest = $this->handle($middleware, new ServerRequest(method: 'GET', uri: '/test'));

        self::assertInstanceOf(AnonymousIdentity::class, $capturedRequest->getAttribute('_identity'));
        self::assertInstanceOf(AnonymousIdentity::class, $capturedRequest->getAttribute('identity'));
    }

    #[Test]
    public function callsNextHandler(): void
    {
        $authManager = $this->createStub(AuthManagerInterface::class);
        $middleware = new AuthenticationMiddleware($authManager, new AuthenticationState());

        $handlerCalled = false;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$handlerCalled): ResponseInterface {
                $handlerCalled = true;

                return new Response(statusCode: ResponseStatus::OK->value, body: 'OK');
            },
        );

        $response = $middleware->process(new ServerRequest(method: 'GET', uri: '/test'), $handler);

        self::assertTrue($handlerCalled);
        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function doesNotCallAuthManagerAuthenticate(): void
    {
        $authManager = $this->createMock(AuthManagerInterface::class);
        $authManager->expects(self::never())->method('authenticate');

        $middleware = new AuthenticationMiddleware($authManager, new AuthenticationState());

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: ResponseStatus::OK->value, body: 'OK'));

        $middleware->process(new ServerRequest(method: 'GET', uri: '/test'), $handler);
    }

    // -----------------------------------------------------------------
    // The identity attributes are an output, never an input
    // -----------------------------------------------------------------

    #[Test]
    public function anIdentityAttributeOnTheRequestDoesNotNameTheCaller(): void
    {
        // The attribute used to be read back and believed, which made
        // authentication decidable by anything that could reach the request:
        // an application middleware, an extension, any global frame piped ahead
        // of this one. Everything downstream that authorizes — the `auth` alias,
        // and the model binding layer through AuthenticationState — inherited
        // that.
        $authManager = $this->createStub(AuthManagerInterface::class);
        $state = new AuthenticationState();

        $forged = new Identity(
            id: 'attacker',
            displayName: 'Whoever I Say I Am',
            roles: ['admin'],
            twoFactorStatus: TwoFactorStatus::Verified,
        );

        $capturedRequest = $this->handle(
            new AuthenticationMiddleware($authManager, $state),
            new ServerRequest(
                method: 'GET',
                uri: '/dashboard',
                attributes: ['identity' => $forged, '_identity' => $forged],
            ),
        );

        self::assertNull(
            $state->authenticatedIdentity(),
            'A request attribute must not establish an authenticated caller.',
        );
        self::assertInstanceOf(AnonymousIdentity::class, $capturedRequest->getAttribute('_identity'));
        self::assertInstanceOf(AnonymousIdentity::class, $capturedRequest->getAttribute('identity'));
    }

    #[Test]
    public function aNonIdentityAttributeIsOverwrittenWithAnonymous(): void
    {
        $authManager = $this->createStub(AuthManagerInterface::class);

        $capturedRequest = $this->handle(
            new AuthenticationMiddleware($authManager, new AuthenticationState()),
            new ServerRequest(
                method: 'GET',
                uri: '/dashboard',
                attributes: ['identity' => 'not-an-identity-object'],
            ),
        );

        self::assertInstanceOf(AnonymousIdentity::class, $capturedRequest->getAttribute('_identity'));
        self::assertInstanceOf(AnonymousIdentity::class, $capturedRequest->getAttribute('identity'));
    }

    // -----------------------------------------------------------------
    // The one channel that does establish a caller before the guards
    // -----------------------------------------------------------------

    #[Test]
    public function honoursAContextEstablishedBeforeTheKernel(): void
    {
        // `pulsar serve --dev-identity`. It knows who the developer is without a
        // credential in the request, and it says so where only the composition
        // root can: by publishing an already-resolved SecurityContext into the
        // holder before Kernel::handle() runs. This middleware must leave that
        // alone rather than replacing it with a lazy one.
        $authManager = $this->createStub(AuthManagerInterface::class);
        $state = new AuthenticationState();
        $request = new ServerRequest(method: 'GET', uri: '/dev/dashboard');

        $devIdentity = new Identity(
            id: 'dev-admin',
            displayName: 'Dev Administrator',
            roles: ['admin'],
            twoFactorStatus: TwoFactorStatus::Verified,
        );

        $established = SecurityContext::established($authManager, $request, $devIdentity);
        $state->establish($established);

        $capturedRequest = $this->handle(new AuthenticationMiddleware($authManager, $state), $request);

        self::assertSame($established, $state->context());
        self::assertSame($devIdentity, $state->authenticatedIdentity());
        self::assertSame($devIdentity, $capturedRequest->getAttribute('_identity'));
        self::assertSame($devIdentity, $capturedRequest->getAttribute('identity'));
    }

    #[Test]
    public function aResetHolderStopsCarryingTheCallerIntoTheNextRequest(): void
    {
        // A resident worker reuses one key for every request it serves. Without
        // the reset RuntimeWiring registers, the first caller of the worker is
        // every later caller.
        $authManager = $this->createStub(AuthManagerInterface::class);
        $state = new AuthenticationState();
        $request = new ServerRequest(method: 'GET', uri: '/dev/dashboard');

        $state->establish(SecurityContext::established(
            $authManager,
            $request,
            new Identity(id: 'first-caller', displayName: 'First', roles: [], twoFactorStatus: TwoFactorStatus::Disabled),
        ));

        $state->resetRequestState();

        $capturedRequest = $this->handle(new AuthenticationMiddleware($authManager, $state), $request);

        self::assertNull($state->authenticatedIdentity());
        self::assertInstanceOf(AnonymousIdentity::class, $capturedRequest->getAttribute('_identity'));
    }

    /**
     * Run the middleware and hand back the request the handler saw.
     */
    private function handle(AuthenticationMiddleware $middleware, ServerRequest $request): ServerRequestInterface
    {
        $captured = null;

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$captured): ResponseInterface {
                $captured = $req;

                return new Response(statusCode: ResponseStatus::OK->value, body: 'OK');
            },
        );

        $middleware->process($request, $handler);

        self::assertInstanceOf(ServerRequestInterface::class, $captured);

        return $captured;
    }
}
