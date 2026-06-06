<?php

declare(strict_types=1);

namespace Pulsar\Auth\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;
use Pulsar\Security\Session\SessionInterface;

use function str_contains;

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
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var SecurityContext|null $securityContext */
        $securityContext = $request->getAttribute('_security_context');

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

        return $handler->handle($request);
    }

    /**
     * Mark an identity as step-up authenticated in the session.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public static function markStepUpAuthenticated(SessionInterface $session, string $identityId): void
    {
        $sessionKey = self::SESSION_KEY_PREFIX . '[' . $identityId . ']';
        $session->set($sessionKey, time());
    }

    private function forbiddenResponse(ServerRequestInterface $request): ResponseInterface
    {
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return Response::json(
                ['error' => 'Step-up authentication required', 'status' => 403],
                ResponseStatus::Forbidden->value,
            );
        }

        return new Response(
            statusCode: ResponseStatus::Forbidden->value,
            body: 'Step-up authentication required',
        );
    }
}
