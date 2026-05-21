<?php

declare(strict_types=1);

namespace Pulsar\Auth\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Identity\LevelOfAssurance;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;

use function str_contains;

/**
 * Route-level middleware enforcing eIDAS Level of Assurance requirements.
 *
 * Maps the current authentication state to an eIDAS assurance level:
 * - No authentication or no 2FA → Low
 * - 2FA verified → Substantial
 * - Step-up or hardware token (attribute `_loa_high`) → High
 *
 * Routes declare the minimum required LoA. Requests that do not meet
 * the threshold receive 403 Forbidden.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
final readonly class LevelOfAssuranceMiddleware implements MiddlewareInterface
{
    public const string LOA_ATTRIBUTE = '_eidas_loa';

    public function __construct(
        private LevelOfAssurance $requiredLevel,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $currentLevel = self::resolveLevel($request);

        if (! $currentLevel->satisfies($this->requiredLevel)) {
            return $this->forbiddenResponse($request);
        }

        return $handler->handle(
            $request->withAttribute(self::LOA_ATTRIBUTE, $currentLevel),
        );
    }

    /**
     * Determine the effective LoA from the request's authentication state.
     */
    public static function resolveLevel(ServerRequestInterface $request): LevelOfAssurance
    {
        // Explicit LoA override (e.g., from hardware token guard)
        /** @var mixed $explicit */
        $explicit = $request->getAttribute('_loa_high');

        if ($explicit === true) {
            return LevelOfAssurance::High;
        }

        /** @var SecurityContext|null $ctx */
        $ctx = $request->getAttribute('_security_context');

        if ($ctx === null) {
            return LevelOfAssurance::Low;
        }

        $identity = $ctx->identity();

        if (! $identity->isAuthenticated()) {
            return LevelOfAssurance::Low;
        }

        if ($identity->twoFactorStatus() === TwoFactorStatus::Verified) {
            return LevelOfAssurance::Substantial;
        }

        return LevelOfAssurance::Low;
    }

    private function forbiddenResponse(ServerRequestInterface $request): ResponseInterface
    {
        $message = 'Insufficient assurance level: '
            . $this->requiredLevel->value . ' required';

        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return Response::json(
                [
                    'error' => $message,
                    'required_loa' => $this->requiredLevel->value,
                    'status' => 403,
                ],
                ResponseStatus::Forbidden->value,
            );
        }

        return new Response(
            statusCode: ResponseStatus::Forbidden->value,
            body: $message,
        );
    }
}
