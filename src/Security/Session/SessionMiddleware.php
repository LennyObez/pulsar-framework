<?php

declare(strict_types=1);

namespace Pulsar\Security\Session;

use Closure;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\ErrorHandling\ExceptionRendererInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Session\Flash\FlashBag;

use function htmlspecialchars;
use function sprintf;
use function str_contains;

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
    /**
     * @param (Closure(): ?ExceptionRendererInterface)|null $errorRendererResolver
     *     Lazy resolver for the error-page renderer (wired after this middleware),
     *     used to theme and localize the 401 shown when an authenticated session
     *     expires; null yields a minimal inline page.
     */
    public function __construct(
        private SessionManager $sessionManager,
        private FlashBag $flashBag,
        private ?LoggerInterface $logger = null,
        private ?Closure $errorRendererResolver = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $this->sessionManager->startWithRequest($request);
        } catch (SecurityException $e) {
            // Anonymous idle/validator expiry is recovered transparently inside the
            // manager and never reaches here; only an AUTHENTICATED session throws.
            // A stale authenticated cookie is a client-state problem requiring
            // re-authentication, so answer 401 (never 500). The response is RETURNED,
            // not thrown, so it flows back out through the outer middleware — notably
            // SecurityHeadersMiddleware — and carries the stale-cookie clears.
            if (
                $e->getCode() !== SecurityException::CODE_SESSION_IDLE_EXPIRED
                && $e->getCode() !== SecurityException::CODE_SESSION_VALIDATION_FAILED
            ) {
                throw $e;
            }

            return $this->sessionExpiredResponse($request, $e);
        }

        // Anonymous session was transparently regenerated after idle/validator
        // expiry: surface it for observability and let apps show a notice, but the
        // request continues normally with the fresh session.
        if ($this->sessionManager->recoveredFromExpiry()) {
            $this->logger?->info('Anonymous session regenerated after idle/validator expiry.');
            $request = $request->withAttribute('session.expired', true);
        }

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

    /**
     * Build the 401 response for an expired authenticated session, content-negotiated
     * like {@see \Pulsar\Security\Csrf\CsrfMiddleware}: JSON for API/XHR clients, else
     * HTML from the configured error-page renderer (themed/localized) with a minimal
     * inline fallback. Returned (not thrown) so it flows back through the outer
     * middleware and thus carries the application security headers, and it expires the
     * stale id and (stateless-handler) payload cookies so no dead client state remains.
     */
    private function sessionExpiredResponse(ServerRequestInterface $request, SecurityException $cause): ResponseInterface
    {
        $message = 'Your session has expired. Please sign in again.';
        $status = ResponseStatus::Unauthorized;

        if (
            str_contains($request->getHeaderLine('Accept'), 'application/json')
            || $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest'
        ) {
            $response = Response::json(
                ['error' => 'session_expired', 'message' => $message],
                $status->value,
            );
        } else {
            $renderer = $this->errorRendererResolver !== null ? ($this->errorRendererResolver)() : null;

            $html = $renderer instanceof ExceptionRendererInterface
                ? $renderer->render($cause, $request, $status)
                : sprintf(
                    '<!DOCTYPE html><html><head><title>401 Session Expired</title></head>'
                    . '<body><h1>401 Session Expired</h1><p>%s</p></body></html>',
                    htmlspecialchars($message),
                );

            $response = Response::html($html, $status->value);
        }

        // Expire both the id cookie and, for the stateless CookieHandler, the companion
        // payload cookie. withAddedHeader lets multiple Set-Cookie headers coexist,
        // which the single-value HttpException header map could not.
        foreach ([$this->sessionManager->pendingSetCookieHeader(), $this->sessionManager->pendingPayloadCookieHeader()] as $cookie) {
            if ($cookie !== null) {
                $response = $response->withAddedHeader('Set-Cookie', $cookie);
            }
        }

        return $response;
    }
}
