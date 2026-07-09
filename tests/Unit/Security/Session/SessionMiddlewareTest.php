<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session;

use Closure;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\SecurityHeadersConfig;
use Pulsar\Config\SessionConfig;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\Middleware\SecurityHeadersMiddleware;
use Pulsar\Security\Session\Flash\FlashBag;
use Pulsar\Security\Session\Handler\ArrayHandler;
use Pulsar\Security\Session\SessionManager;
use Pulsar\Security\Session\SessionMetadata;
use Pulsar\Security\Session\SessionMiddleware;
use ReflectionClass;
use stdClass;

use function bin2hex;
use function json_encode;
use function random_bytes;
use function time;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

#[CoversClass(SessionMiddleware::class)]
final class SessionMiddlewareTest extends TestCase
{
    private SessionManager $sessionManager;

    private FlashBag $flashBag;

    private SessionMiddleware $middleware;

    protected function setUp(): void
    {
        $handler = new ArrayHandler();
        $config = new SessionConfig(
            cookieName: 'TEST_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: false,
        );

        $this->sessionManager = new SessionManager($handler, $config);
        $this->flashBag = new FlashBag($this->sessionManager);
        $this->middleware = new SessionMiddleware($this->sessionManager, $this->flashBag);
    }

    private function createRequest(): ServerRequestInterface
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'PHPUnit'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );
    }

    #[Test]
    public function sessionStartedBeforeNext(): void
    {
        $request = $this->createRequest();
        $capture = new stdClass();
        $capture->wasStarted = false;
        $sessionManager = $this->sessionManager;

        $handler = new SessionMiddlewareTestHandler(static function () use ($sessionManager, $capture): ResponseInterface {
            $capture->wasStarted = $sessionManager->isStarted();

            return new Response();
        });

        $this->middleware->process($request, $handler);

        self::assertTrue($capture->wasStarted, 'Session should be started before next middleware runs');
    }

    #[Test]
    public function flashMessagesAged(): void
    {
        // Start session, set flash data, and save (simulates previous request)
        $this->sessionManager->start();
        $this->flashBag->set('notice', 'Previous request flash');
        $this->sessionManager->save();

        $handler = $this->sessionManager->getHandler();
        $sessionId = $this->sessionManager->id();
        $config = $this->sessionManager->getConfig();

        // Create a new manager with the same handler to simulate a fresh request
        $newManager = new SessionManager($handler, $config);

        $reflection = new ReflectionClass($newManager);
        $idProperty = $reflection->getProperty('sessionId');
        $idProperty->setValue($newManager, $sessionId);

        $flashBag = new FlashBag($newManager);
        $middleware = new SessionMiddleware($newManager, $flashBag);

        // Create a PSR-7 request with the session cookie
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'PHPUnit'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
            cookieParams: ['TEST_SESSION' => $sessionId],
        );

        $capture = new stdClass();
        $capture->flashAvailable = false;

        $nextHandler = new SessionMiddlewareTestHandler(static function () use ($flashBag, $capture): ResponseInterface {
            $capture->flashAvailable = $flashBag->has('notice');

            return new Response();
        });

        $middleware->process($request, $nextHandler);

        self::assertTrue($capture->flashAvailable, 'Flash data should be available after age()');
    }

    #[Test]
    public function anonymousExpiredSessionContinuesWithFreshSessionAndExpiredAttribute(): void
    {
        [$middleware, $manager, $sessionId] = $this->buildExpiredScenario(authenticated: false);

        $captured = new stdClass();
        $captured->expiredAttr = null;

        $handler = new SessionMiddlewareTestHandler(static function (ServerRequestInterface $req) use ($captured): ResponseInterface {
            $captured->expiredAttr = $req->getAttribute('session.expired');

            return new Response();
        });

        $response = $middleware->process($this->requestWithCookie($sessionId), $handler);

        self::assertSame(200, $response->getStatusCode(), 'An anonymous expired session must not produce an error status.');
        self::assertTrue($manager->isStarted());
        self::assertNotSame($sessionId, $manager->id(), 'A fresh, rotated session id must be minted.');
        self::assertTrue($captured->expiredAttr, 'Downstream handlers must see the session.expired request attribute.');
    }

    #[Test]
    public function authenticatedExpiredSessionReturns401AndClearsCookie(): void
    {
        [$middleware, , $sessionId] = $this->buildExpiredScenario(authenticated: true);

        $handler = new SessionMiddlewareTestHandler(
            static fn(ServerRequestInterface $req): ResponseInterface => new Response(),
        );

        // Returned (not thrown) so it flows back through the outer middleware for
        // security headers and can carry the stale-cookie clears.
        $response = $middleware->process($this->requestWithCookie($sessionId), $handler);

        self::assertSame(401, $response->getStatusCode(), 'Expired authenticated session must be a 4xx, never a 5xx.');
        self::assertNotEmpty($response->getHeader('Set-Cookie'), 'The stale session cookie must be expired on the 401.');
    }

    #[Test]
    public function authenticatedExpired401FlowsThroughSecurityHeadersMiddleware(): void
    {
        // Because the 401 is returned (not thrown), composing SecurityHeadersMiddleware
        // around SessionMiddleware proves the expired-session response still carries the
        // application security headers — a thrown exception would escape the pipeline
        // and ship a header-less error page.
        [$sessionMiddleware, , $sessionId] = $this->buildExpiredScenario(authenticated: true);

        $securityHeaders = new SecurityHeadersMiddleware(new SecurityHeadersConfig(headers: []));

        $appHandler = new SessionMiddlewareTestHandler(
            static fn(ServerRequestInterface $req): ResponseInterface => new Response(),
        );
        $sessionAsHandler = new SessionMiddlewareTestHandler(
            static fn(ServerRequestInterface $req): ResponseInterface => $sessionMiddleware->process($req, $appHandler),
        );

        $response = $securityHeaders->process($this->requestWithCookie($sessionId), $sessionAsHandler);

        self::assertSame(401, $response->getStatusCode());
        self::assertNotEmpty(
            $response->getHeaderLine('X-Frame-Options'),
            'The expired-session 401 must carry the application security headers.',
        );
        self::assertNotEmpty($response->getHeaderLine('Content-Security-Policy'));
    }

    /**
     * @return array{0: SessionMiddleware, 1: SessionManager, 2: string}
     */
    private function buildExpiredScenario(bool $authenticated): array
    {
        $handler = new ArrayHandler();
        $config = new SessionConfig(
            cookieName: 'TEST_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: false,
            idleTimeout: 300,
        );

        $sessionId = bin2hex(random_bytes(32));
        $metadata = new SessionMetadata(
            createdAt: time() - 3600,
            lastActivity: time() - 600, // idle beyond the 300s timeout
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit',
        );

        $data = ['old' => 'value'];

        if ($authenticated) {
            // The guard's identity key in session data marks this as authenticated.
            $data['_pulsar_identity'] = ['id' => 'user-1'];
        }

        $handler->open('', 'TEST_SESSION');
        $handler->write($sessionId, json_encode(
            ['data' => $data, '_pulsar_meta' => $metadata->toArray()],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        $manager = new SessionManager($handler, $config);
        $middleware = new SessionMiddleware($manager, new FlashBag($manager));

        return [$middleware, $manager, $sessionId];
    }

    private function requestWithCookie(string $sessionId): ServerRequestInterface
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'PHPUnit'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
            cookieParams: ['TEST_SESSION' => $sessionId],
        );
    }

    #[Test]
    public function sessionSavedAfterResponse(): void
    {
        $request = $this->createRequest();
        $sessionManager = $this->sessionManager;

        $nextHandler = new SessionMiddlewareTestHandler(static function () use ($sessionManager): ResponseInterface {
            $sessionManager->set('persisted', 'value');

            return new Response();
        });

        $this->middleware->process($request, $nextHandler);

        // After middleware completes, session should be saved
        $handler = $this->sessionManager->getHandler();
        $sessionId = $this->sessionManager->id();
        $raw = $handler->read($sessionId);

        self::assertIsString($raw);
        self::assertNotEmpty($raw, 'Session data should have been saved to handler');
        self::assertStringContainsString('persisted', $raw);
    }

    #[Test]
    public function middlewareReturnsResponseFromNext(): void
    {
        $request = $this->createRequest();
        $expectedResponse = new Response(statusCode: 200, body: 'Custom body');

        $handler = new SessionMiddlewareTestHandler(static function () use ($expectedResponse): ResponseInterface {
            return $expectedResponse;
        });

        $result = $this->middleware->process($request, $handler);

        // The handler's response is passed through with its status and body
        // intact. The middleware adds a Set-Cookie header for this new session,
        // so it returns a PSR-7-derived response rather than the identical
        // object — assert on the passed-through content, not object identity.
        self::assertSame(200, $result->getStatusCode());
        self::assertSame('Custom body', (string) $result->getBody());
        self::assertNotEmpty($result->getHeader('Set-Cookie'), 'new session must emit its cookie');
    }
}

/**
 * Simple RequestHandler that delegates to a callback.
 */
final class SessionMiddlewareTestHandler implements RequestHandlerInterface
{
    /** @var Closure(ServerRequestInterface): ResponseInterface */
    private Closure $callback;

    /**
     * @param Closure(ServerRequestInterface): ResponseInterface $callback
     */
    public function __construct(Closure $callback)
    {
        $this->callback = $callback;
    }

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->callback)($request);
    }
}
