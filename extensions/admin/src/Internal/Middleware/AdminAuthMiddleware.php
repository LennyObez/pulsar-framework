<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Exception\AdminAccessDeniedException;
use Pulsar\Extension\Admin\Internal\Security\AdminAccessGate;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;

/**
 * Enforces authentication and role requirements for admin routes.
 */
#[Internal]
final readonly class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AdminAccessGate $accessGate,
        private AdminConfig $config,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            return Response::json(
                ['error' => 'Authentication required'],
                ResponseStatus::Unauthorized->value,
            );
        }

        if ($this->config->security->require2fa) {
            $twoFaStatus = $identity->twoFactorStatus();
            if ($twoFaStatus !== TwoFactorStatus::Verified) {
                return Response::json(
                    ['error' => 'Two-factor authentication required for admin access'],
                    ResponseStatus::Forbidden->value,
                );
            }
        }

        try {
            $this->accessGate->assertCanAccess($identity);
        } catch (AdminAccessDeniedException $e) {
            return Response::json(
                ['error' => $e->getMessage()],
                ResponseStatus::Forbidden->value,
            );
        }

        return $handler->handle($request);
    }
}
