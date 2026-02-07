<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\SessionConfig;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Security\Session\Handler\ArrayHandler;
use Pulsar\Security\Session\SessionManager;

/**
 * Benchmark session middleware.
 *
 * Starts and saves an in-memory session per request.
 * Uses ArrayHandler and minimal SessionConfig for Tier A benchmarks.
 */
final class BenchSessionMiddleware implements MiddlewareInterface
{
    private readonly SessionManager $sessionManager;

    public function __construct()
    {
        $this->sessionManager = new SessionManager(
            handler: new ArrayHandler(),
            config: new SessionConfig(
                cookieName: 'BENCH_SESSION',
                lifetime: 3600,
                cookieHttpOnly: true,
                cookieSecure: false,
                cookieSameSite: 'Lax',
                regenerateOnPrivilegeChange: false,
                handler: 'array',
                encryption: false,
            ),
        );
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->sessionManager->start();

        $this->sessionManager->set('bench_key', 'bench_value');

        $response = $handler->handle($request);

        $this->sessionManager->save();

        return $response;
    }

    public function sessionManager(): SessionManager
    {
        return $this->sessionManager;
    }
}
