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
 * @api
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

        // Emit the session cookie so the client retains the session id across
        // requests — without this, session-backed CSRF (token stored server-side
        // in the session) can never validate a GET-rendered token on the POST.
        // Only sent when the id is new/rotated or the session was destroyed;
        // withAddedHeader preserves any other Set-Cookie headers (device, locale).
        $setCookie = $this->sessionManager->pendingSetCookieHeader();

        if ($setCookie !== null) {
            $response = $response->withAddedHeader('Set-Cookie', $setCookie);
        }

        // Stateless cookie handler only: emit the companion cookie carrying the
        // encrypted session payload so the session body persists across requests.
        // Returns null for server-backed handlers, leaving the response untouched.
        $payloadCookie = $this->sessionManager->pendingPayloadCookieHeader();

        if ($payloadCookie !== null) {
            $response = $response->withAddedHeader('Set-Cookie', $payloadCookie);
        }

        return $response;
    }
}
