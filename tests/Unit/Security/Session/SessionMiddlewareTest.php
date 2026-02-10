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
use Pulsar\Config\SessionConfig;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\Session\Flash\FlashBag;
use Pulsar\Security\Session\Handler\ArrayHandler;
use Pulsar\Security\Session\SessionManager;
use Pulsar\Security\Session\SessionMiddleware;
use ReflectionClass;
use stdClass;

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

        self::assertSame($expectedResponse, $result);
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
