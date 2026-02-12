<?php

declare(strict_types=1);

namespace Pulsar\Security\Session;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Security\Session\Flash\FlashBag;

/**
 * Middleware that manages the session lifecycle per request.
 *
 * Starts the session, ages flash messages, then saves and closes the session
 * after the downstream middleware pipeline has processed the request.
 */
#[Api(since: '1.0.0')]
final readonly class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private SessionManager $sessionManager,
        private FlashBag $flashBag,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->sessionManager->startWithRequest($request);

        $this->flashBag->age();

        $response = $handler->handle($request);

        $this->sessionManager->save();

        return $response;
    }
}
