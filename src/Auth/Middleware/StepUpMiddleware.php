<?php

declare(strict_types=1);

namespace Pulsar\Auth\Middleware;

use Override;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Security\Session\SessionInterface;

/**
 * Route-level middleware requiring step-up authentication for sensitive operations.
 *
 * Checks for a per-identity step-up timestamp in the session. If missing or expired,
 * returns 403 Forbidden. Identity scoping prevents cross-account step-up inheritance.
 */
final readonly class StepUpMiddleware implements MiddlewareInterface
{
    private const string SESSION_KEY_PREFIX = '_pulsar_step_up';

    public function __construct(
        private SessionInterface $session,
        private int $timeoutMinutes = 15,
    ) {}

    #[Override]
    public function process(Request $request, callable $next): Response
    {
        /** @var SecurityContext|null $securityContext */
        $securityContext = $request->attribute('_security_context');

        if ($securityContext === null) {
            return $this->forbiddenResponse($request);
        }

        $identity = $securityContext->identity();

        if (!$identity->isAuthenticated()) {
            return $this->forbiddenResponse($request);
        }

        $identityId = $identity->id();
        $sessionKey = self::SESSION_KEY_PREFIX . '[' . $identityId . ']';

        /** @var int|null $stepUpAt */
        $stepUpAt = $this->session->get($sessionKey);

        if ($stepUpAt === null) {
            return $this->forbiddenResponse($request);
        }

        $expiresAt = $stepUpAt + ($this->timeoutMinutes * 60);

        if (time() > $expiresAt) {
            return $this->forbiddenResponse($request);
        }

        return $next($request);
    }

    /**
     * Mark an identity as step-up authenticated in the session.
     */
    public static function markStepUpAuthenticated(SessionInterface $session, string $identityId): void
    {
        $sessionKey = self::SESSION_KEY_PREFIX . '[' . $identityId . ']';
        $session->set($sessionKey, time());
    }

    private function forbiddenResponse(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json(
                ['error' => 'Step-up authentication required', 'status' => 403],
                ResponseStatus::Forbidden,
            );
        }

        return new Response(
            body: 'Step-up authentication required',
            status: ResponseStatus::Forbidden,
        );
    }
}
